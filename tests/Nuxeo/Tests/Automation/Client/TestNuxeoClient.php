<?php
/*
 * (C) Copyright 2015 Nuxeo SA (http://nuxeo.com/) and contributors.
 *
 * All rights reserved. This program and the accompanying materials
 * are made available under the terms of the GNU Lesser General Public License
 * (LGPL) version 2.1 which accompanies this distribution, and is available at
 * http://www.gnu.org/licenses/lgpl-2.1.html
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * Contributors:
 *     Pierre-Gildas MILLON <pgmillon@nuxeo.com>
 */

namespace Nuxeo\Tests\Automation\Client;

use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Message\Response;
use Guzzle\Tests\Http\Server;
use Nuxeo\Automation\Client\NuxeoPhpAutomationClient;

class TestNuxeoClient extends \PHPUnit_Framework_TestCase {

  const LOGIN = "Administrator";
  const PASSWORD = "Administrator";
  const MYFILE_CONTENT = 'Hello World';
  const MYFILE_DOCPATH = '/default-domain/workspaces/MyWorkspace/MyFile';
  const NEWFILE_NAME = 'myfile.txt';
  const NEWFILE_PATH = '_files/'.self::NEWFILE_NAME;
  const NEWFILE_TYPE = 'text/plain';

  /**
   * @var Server
   */
  protected $server;

  protected function setUp() {
    $this->server = new Server();
    $this->server->start();
  }

  protected function tearDown() {
    $this->server->stop();
  }

  public function readPartFromFile($path) {
    $part = file_get_contents($path);
    return str_replace(PHP_EOL, "\r\n", $part);
  }

  public function testGetRequest() {
    $client = new NuxeoPhpAutomationClient($this->server->getUrl());
    $session = $client->getSession(self::LOGIN, self::PASSWORD);
    $request = $session->newRequest("Document.Query");

    $this->assertNotNull($request);
  }

  public function testListDocuments() {
    $client = new NuxeoPhpAutomationClient($this->server->getUrl());
    $session = $client->getSession(self::LOGIN, self::PASSWORD);

    $this->server->enqueue(array(
      new Response(200, null, file_get_contents('_files/document-list.json'))
    ));

      $answer = $session->newRequest("Document.Query")
      ->set('params', 'query', "SELECT * FROM Document")
      ->setSchema("*")
      ->sendRequest();

    $documentsArray = $answer->getDocumentList();

    $this->assertEquals(5, sizeof($documentsArray));

    foreach ($documentsArray as $document) {
      $this->assertNotNull($document->getUid());
      $this->assertNotNull($document->getPath());
      $this->assertNotNull($document->getType());
      $this->assertNotNull($document->getState());
      $this->assertNotNull($document->getTitle());
      $this->assertNotNull($document->getProperty('dc:created'));
    }

    $domain = $answer->getDocument(0);
    $this->assertNotNull($domain);
    $this->assertEquals('Domain', $domain->getType());
    $this->assertEquals('Domain', $domain->getProperty('dc:title'));
    $this->assertNull($domain->getProperty('dc:nonexistent'));

  }

  public function testGetBlob() {
    $client = new NuxeoPhpAutomationClient($this->server->getUrl());
    $session = $client->getSession(self::LOGIN, self::PASSWORD);

    $this->server->enqueue(array(
      new Response(200, null, self::MYFILE_CONTENT)
    ));

    $answer = $session->newRequest("Blob.Get")
      ->set('input', 'doc:'.self::MYFILE_DOCPATH)
      ->sendRequest();

    $this->assertEquals(self::MYFILE_CONTENT, $answer);

  }

  public function testLoadBlob() {
    $client = new NuxeoPhpAutomationClient($this->server->getUrl());
    $session = $client->getSession(self::LOGIN, self::PASSWORD);

    $request = $session->newRequest("Blob.Attach")
      ->set('params', 'document', array(
        "entity-type" => "string",
        "value" => self::MYFILE_DOCPATH
      ))
      ->loadBlob(self::NEWFILE_PATH, self::NEWFILE_TYPE);

    $blobList = $request->getBlobList();

    $this->assertTrue(is_array($blobList));
    $this->assertEquals(1, count($blobList));

    $blob = $blobList[0];

    $this->assertTrue(is_array($blob));
    $this->assertEquals(3, count($blob));
    $this->assertEquals(self::NEWFILE_NAME, $blob[0]);
    $this->assertEquals(self::NEWFILE_TYPE, $blob[1]);
    $this->assertEquals(file_get_contents(self::NEWFILE_PATH), $blob[2]);
  }

  public function testAttachBlob() {
    $client = new NuxeoPhpAutomationClient($this->server->getUrl());
    $session = $client->getSession(self::LOGIN, self::PASSWORD);

    $this->server->enqueue(array(
      new Response(200)
    ));

    $session->newRequest("Blob.Attach")
      ->set('params', 'document', array(
        "entity-type" => "string",
        "value" => self::MYFILE_DOCPATH
      ))
      ->loadBlob(self::NEWFILE_PATH, self::NEWFILE_TYPE)
      ->sendRequest();

    $requests = $this->server->getReceivedRequests(true);

    $this->assertEquals(1, count($requests));

    /** @var EntityEnclosingRequest $request */
    $request = $requests[0];

    $this->assertArrayHasKey('content-type', $request->getHeaders());
    $this->assertStringMatchesFormat(
      'multipart/related;boundary="%s";type="application/json+nxrequest";start="request"',
      $request->getHeader('content-type')->__toString());

    $this->assertArrayHasKey('x-nxvoidoperation', $request->getHeaders());
    $this->assertEquals('true', $request->getHeader('x-nxvoidoperation')->__toString());

    $this->assertArrayHasKey('accept', $request->getHeaders());
    $this->assertEquals('application/json+nxentity, */*', $request->getHeader('accept')->__toString());

    $this->assertContains($this->readPartFromFile('_files/setblob-part1.txt'), $request->getBody()->__toString());
    $this->assertContains($this->readPartFromFile('_files/setblob-part2.txt'), $request->getBody()->__toString());

  }

}