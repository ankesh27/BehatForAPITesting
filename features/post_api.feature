Feature: GitHub RestFul Api Testing with Behat

@1
Scenario: POST Request
    Given I add "content-type" header equal to "application/json"
    When I send a POST request to "oauth2/token" with body:
    """
    {
        "grant_type" : "password",
        "client_id" : "drupal",
        "client_secret" : "1234",
        "username" : "testing_team@srijan.net",
        "password": "12345"
    }
    """
    And the response status code should be 200
    Then print response
    Then the response should be in JSON
    And the JSON node "access_token" should exist
    And the JSON node "expires_in" should exist
    And the JSON node "token_type" should exist
    And the JSON node "scope" should exist
    And the JSON node "refresh_token" should exist
    Then the JSON node "scope" should contain "basighhc"
    Then the JSON node "token_type" should contain "Bearer"
    And print request

#Given the "expires_in" property is a integer equalling '43200'
#And the JSON node "Server" should exist
#Then print request and response
