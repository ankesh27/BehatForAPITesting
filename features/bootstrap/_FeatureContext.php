<?php

use mageekguy\atoum\asserter;
use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;


use Behat\Behat\Context\ClosuredContextInterface,
    Behat\Behat\Context\TranslatedContextInterface,
    Behat\Behat\Context\BehatContext,
    Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;

/**
 * Test workflow totally copied from https://github.com/Behat/WebApiExtension/blob/master/features/bootstrap/FeatureContext.php
 */
class FeatureContext implements Context, SnippetAcceptingContext
{
    private $phpBin;

    private $process;

    private $workingDir;

    private $asserter;
    public $_response;
    /**
 * The Guzzle HTTP Response.
 */
protected $response;

    /**
    * The decoded response object.
    */
   protected $responsePayload;

    public function __construct()
    {
        $this->asserter = new asserter\generator();
    }

    /**
     * @BeforeSuite
     * @AfterSuite
     */
    public static function cleanTestFolders()
    {
        $dir = self::workingDir();

        if (is_dir($dir)) {
            self::clearDirectory($dir);
        }
    }

    /**
     * @BeforeScenario
     */
    public function prepareScenario()
    {
        $dir = self::workingDir() . DIRECTORY_SEPARATOR . (md5(microtime(true) * rand(0, 10000)));
        mkdir($dir . '/features/bootstrap', 0777, true);

        $phpFinder = new PhpExecutableFinder();

        if (false === $php = $phpFinder->find()) {
            throw new \RuntimeException('Unable to find the PHP executable.');
        }

        $this->workingDir = $dir;
        $this->phpBin = $php;
        $this->process = new Process(null);
    }

    /**
     * @Given /^a file named "(?P<filename>[^"]*)" with:$/
     */
    public function aFileNamedWith($filename, PyStringNode $fileContent)
    {
        $content = strtr((string) $fileContent, array("'''" => '"""'));
        $this->createFile($this->workingDir . '/' . $filename, $content);
    }

    /**
     * @When /^I run behat "(?P<arguments>[^"]*)"$/
     */
    public function iRunBehat($arguments)
    {
        $argumentsString = strtr($arguments, array('\'' => '"'));
        $this->process->setWorkingDirectory($this->workingDir);
        $this->process->setCommandLine(sprintf(
            '%s %s %s %s',
            $this->phpBin,
            escapeshellarg(BEHAT_BIN_PATH),
            $argumentsString,
            strtr('--no-colors', array('\'' => '"', '"' => '\"'))
        ));
        $this->process->start();
        $this->process->wait();
    }

    /**
     * @Then /^it should (fail|pass) with:$/
     */
    public function itShouldTerminateWithStatusAndContent($exitStatus, PyStringNode $string)
    {
        if ('fail' === $exitStatus) {
            $this->asserter->integer($this->getExitCode())->isEqualTo(1);
        } elseif ('pass' === $exitStatus) {
            $this->asserter->integer($this->getExitCode())->isEqualTo(0);
        } else {
            throw new \LogicException('Accepts only "fail" or "pass"');
        }

        $stringAsserterFunc = class_exists('mageekguy\\atoum\\asserters\\phpString') ? 'phpString' : 'string';
        $this->asserter->$stringAsserterFunc($this->getOutput())->contains((string) $string);
    }

    private function getExitCode()
    {
        return $this->process->getExitCode();
    }

    private function getOutput()
    {
        $output = $this->process->getErrorOutput() . $this->process->getOutput();

        // Normalize the line endings in the output
        if ("\n" !== PHP_EOL) {
            $output = str_replace(PHP_EOL, "\n", $output);
        }

        return trim(preg_replace("/ +$/m", '', $output));
    }

    private function createFile($filename, $content)
    {
        $path = dirname($filename);

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }

        file_put_contents($filename, $content);
    }

    public static function workingDir()
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'json-api-behat';
    }

    private static function clearDirectory($path)
    {
        $files = scandir($path);
        array_shift($files);
        array_shift($files);

        foreach ($files as $file) {
            $file = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($file)) {
                self::clearDirectory($file);
            } else {
                unlink($file);
            }
        }

        rmdir($path);
    }

    /**
     * @Then /^the response should be JSON$/
     */
    public function theResponseShouldBeJson()
    {
        $data = json_decode($this->_response->getBody(true));

        if (empty($data)) { throw new Exception("Response was not JSON\n" . $this->_response);
       }
    }

    /**
    * Returns the payload from the current scope within
    * the response.
    *
    * @return mixed
    */
   protected function getScopePayload()
   {
       $payload = $this->getResponsePayload();
       if (! $this->scope) {
           return $payload;
       }
       return $this->arrayGet($payload, $this->scope);
   }

   /**
    * Return the response payload from the current response.
    *
    * @return  mixed
    */
   protected function getResponsePayload()
   {
       if (! $this->responsePayload) {
           $json = json_decode($this->getResponse()->getBody(true));
           if (json_last_error() !== JSON_ERROR_NONE) {
               $message = 'Failed to decode JSON body ';
               switch (json_last_error()) {
                   case JSON_ERROR_DEPTH:
                       $message .= '(Maximum stack depth exceeded).';
                       break;
                   case JSON_ERROR_STATE_MISMATCH:
                       $message .= '(Underflow or the modes mismatch).';
                       break;
                   case JSON_ERROR_CTRL_CHAR:
                       $message .= '(Unexpected control character found).';
                       break;
                   case JSON_ERROR_SYNTAX:
                       $message .= '(Syntax error, malformed JSON).';
                       break;
                   case JSON_ERROR_UTF8:
                       $message .= '(Malformed UTF-8 characters, possibly incorrectly encoded).';
                       break;
                   default:
                       $message .= '(Unknown error).';
                       break;
               }
               throw new Exception($message);
           }
           $this->responsePayload = $json;
       }
       return $this->responsePayload;
   }

    /**
     * @Given /^the response has a "([^"]*)" property$/
     */
    public function theResponseHasAProperty($propertyName)
    {
        $data = json_decode($this->_response->getBody(true));
        if (!empty($data)) {
            if (!isset($data->$propertyName)) {
                throw new Exception("Property '".$propertyName."' is not set!\n");
            }
        } else {
            throw new Exception("Response was not JSON\n" . $this->_response->getBody(true));
        }
    }

    /**
    * @Given /^the "([^"]*)" property exists$/
    */
   public function thePropertyExists($property)
   {
       $payload = $this->getScopePayload();
       $message = sprintf(
           'Asserting the [%s] property exists in the scope [%s]: %s',
           $property,
           $this->scope,
           json_encode($payload)
       );
       if (is_object($payload)) {
           assertTrue(array_key_exists($property, get_object_vars($payload)), $message);
       } else {
           assertTrue(array_key_exists($property, $payload), $message);
       }
   }

   /**
   * Checks the response exists and returns it.
   *
   * @return  Guzzle\Http\Message\Response
   */
  protected function getResponse()
  {
      if (! $this->response) {
          throw new Exception("You must first make a request to check a response.");
      }
      return $this->response;
  }

  /**
      * @Then /^I get a "([^"]*)" response$/
      */
     public function iGetAResponse($statusCode)
     {
         $response = $this->getResponse();
         $contentType = $response->getHeader('Content-Type');
         if ($contentType === 'application/json') {
             $bodyOutput = $response->getBody();
         } else {
             $bodyOutput = 'Output is '.$contentType.', which is not JSON and is therefore scary. Run the request manually.';
         }
         assertSame((int) $statusCode, (int) $this->getResponse()->getStatusCode(), $bodyOutput);
     }

    /**
        * @Then I wait for :time seconds
        */
       public function iWaitForSeconds($time)
       {
           sleep($time);
       }
     /**
     * @Then /^the "([^"]*)" property equals "([^"]*)"$/
     */
    public function thePropertyEquals($propertyName, $propertyValue)
    {
        $data = json_decode($this->_response->getBody(true));

        if (!empty($data)) {
            if (!isset($data->$propertyName)) {
                throw new Exception("Property '".$propertyName."' is not set!\n");
            }
            if ($data->$propertyName !== $propertyValue) {
                throw new \Exception('Property value mismatch! (given: '.$propertyValue.', match: '.$data->$propertyName.')');
            }
        } else {
            throw new Exception("Response was not JSON\n" . $this->_response->getBody(true));
        }
}
}
