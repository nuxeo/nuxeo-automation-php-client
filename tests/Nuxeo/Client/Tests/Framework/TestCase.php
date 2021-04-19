<?php
/**
 * (C) Copyright 2017 Nuxeo SA (http://nuxeo.com/) and contributors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

namespace Nuxeo\Client\Tests\Framework;


use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Nuxeo\Client\Constants;
use Nuxeo\Client\NuxeoClient;
use Nuxeo\Client\Tests\Client;

abstract class TestCase extends \PHPUnit\Framework\TestCase {
  const LOGIN = 'Administrator';
  const PASSWORD = 'Administrator';
  const DOC_TITLE = 'myfile.txt';
  const DOC_FS_PATH = 'myfile.txt';
  const DOC_CONTENT = 'Hello World';
  const DOC_MIME = 'text/plain';
  const DOC_PATH = '/default-domain/workspaces/MyWorkspace/MyFile';
  const DOC_TYPE = 'Note';
  const DOC_PARENT_PATH = '/default-domain/workspaces/MyWorkspace';
  const DOC_REPOSITORY = 'default';
  const DOC_UID = '3dd87292-1345-4ad8-acb5-4e74c26bf893';
  const IMG_MIME = 'image/png';
  const IMG_FS_PATH = 'nuxeo.png';
  const URL = 'http://localhost:8080/nuxeo/';

  /**
   * @var Client
   */
  protected $client;

  public function readPartFromFile($path) {
    $part = file_get_contents($this->getResource($path));
    return str_replace(PHP_EOL, "\r\n", $part);
  }

  protected function tearDown() {
    unset($this->client);
  }

  /**
   * @param string $url
   * @param string $username
   * @param string $password
   * @return Client
   */
  protected function getClient($url = self::URL, $username = self::LOGIN, $password = self::PASSWORD) {
    if(null === $this->client) {
      $this->client = new Client($url, $username, $password);
    }
//    return new NuxeoClient('http://localhost:9081/nuxeo');
    return $this->client;
  }

  /**
   * @param int $code
   * @param array $headers
   * @param string $body
   * @return Response
   */
  protected function createResponse($code = 200, array $headers = array(), $body = '') {
    return new Response($code, $headers, $body);
  }

  /**
   * @param string $jsonContent
   * @return Response
   */
  protected function createJsonResponse($jsonContent) {
    return $this->createResponse(200, array('Content-Type' => Constants::CONTENT_TYPE_JSON), $jsonContent);
  }

  protected function createJsonResponseFromFile($relativePath) {
    return $this->createJsonResponse(file_get_contents($this->getResource($relativePath)));
  }

  /**
   * @param Client $client
   * @param string $relativePath
   * @param integer $requestIndex
   */
  public static function assertRequestPathMatches($client, $relativePath, $requestIndex = -1) {
    self::assertEquals(UriResolver::resolve($client->getApiUrl(), new Uri($relativePath))->getPath(), urldecode($client->getRequest($requestIndex)->getUri()->getPath()));
  }

  /**
   * Get the full path to a file located in the tests resources
   * @param string $relativePath
   * @return string
   */
  public function getResource($relativePath) {
    $file = new \SplFileObject($relativePath, 'rb', true);
    return $file->getRealPath();
  }

}
