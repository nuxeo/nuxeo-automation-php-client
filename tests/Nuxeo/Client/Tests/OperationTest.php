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

namespace Nuxeo\Client\Tests;


use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use JMS\Serializer\Annotation as Serializer;
use Nuxeo\Client\NuxeoClient;
use Nuxeo\Client\Objects\Audit\LogEntry;
use Nuxeo\Client\Objects\Blob\Blob;
use Nuxeo\Client\Objects\Document;
use Nuxeo\Client\Objects\Documents;
use Nuxeo\Client\Objects\Operation;
use Nuxeo\Client\Tests\Framework\TestCase;
use Nuxeo\Client\Tests\Objects\Character;
use Nuxeo\Client\Tests\Objects\MyDocType;

class OperationTest extends TestCase {

  public function testListDocuments() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document-list.json'))));

    /** @var Documents $documents */
    $documents = $client
      ->schemas('*')
      ->automation()
      ->param('query', 'SELECT * FROM Document')
      ->execute(null, 'Document.Query');

    $this->assertRequestPathMatches($client, 'automation/Document.Query');
    $this->assertInstanceOf(Documents::class, $documents);
    $this->assertEquals(5, $documents->getCurrentPageSize());

    foreach ($documents as $document) {
      $this->assertNotEmpty($document->getUid());
      $this->assertNotEmpty($document->getPath());
      $this->assertNotEmpty($document->getType());
      $this->assertNotEmpty($document->getState());
      $this->assertNotEmpty($document->getTitle());
      $this->assertNotEmpty($document->getProperty('dc:created'));
    }

    $note = $documents[0];
    $this->assertNotNull($note);
    $this->assertEquals(self::DOC_TYPE, $note->getType());
    $this->assertEquals(self::DOC_TITLE, $note->getProperty('dc:title'));
    $this->assertNull($note->getProperty('dc:nonexistent'));
  }

  public function testMyDocTypeDeserialize() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document.json'))));

    /** @var MyDocType $document */
    $document = $client
      ->schemas('*')
      ->automation('Document.Fetch')
      ->param('value', '0fa9d2a0-e69f-452d-87ff-0c5bd3b30d7d')
      ->execute(MyDocType::class);

    $this->assertRequestPathMatches($client, 'automation/Document.Fetch');
    $this->assertInstanceOf(MyDocType::class, $document);
    $this->assertEquals($document->getCreatedAt(), $document->getProperty('dc:created'));
  }

  public function testComplexProperty() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document.json'))));

    /** @var Document $document */
    $document = $client
      ->schemas('*')
      ->automation('Document.Fetch')
      ->param('value', '0fa9d2a0-e69f-452d-87ff-0c5bd3b30d7d')
      ->execute();

    /** @var Character $doc */
    $doc = $document->getProperty('custom:complex', Character::class);
    $this->assertNotEmpty($doc->name);
  }

  public function testRelatedProperty() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document.json'))))
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document.json'))));

    /** @var Document $document */
    $document = $client
      ->schemas('*')
      ->automation('Document.Fetch')
      ->param('value', '0fa9d2a0-e69f-452d-87ff-0c5bd3b30d7d')
      ->execute();

    /** @var Operation\DocRef $docRef */
    $docRef = $document->getProperty('custom:related', Operation\DocRef::class);
    $this->assertInstanceOf(Operation\DocRef::class, $docRef);
    $this->assertInstanceOf(Document::class, $doc = $docRef->getDocument());
    $this->assertNotEmpty($doc->getPath());
  }

  public function testGetBlob() {
    $client = $this->getClient()
      ->addResponse($this->createResponse(200, array(
        'Content-Type' => self::IMG_MIME,
        'Content-Disposition' => 'attachment; filename*=UTF-8\'\''.self::IMG_FS_PATH
      ), self::DOC_CONTENT));

    /** @var \Nuxeo\Client\Objects\Blob\Blob $blob */
    $blob = $client->automation('Blob.Get')
      ->input(self::DOC_PATH)
      ->execute(Blob::class);

    $this->assertRequestPathMatches($client, 'automation/Blob.Get');
    $this->assertEquals(sprintf('{"params":{},"input":"%s"}', self::DOC_PATH), (string) $client->getRequest()->getBody());
    $this->assertEquals($blob->getStream()->getContents(), self::DOC_CONTENT);
  }

  /**
   * @expectedException \Nuxeo\Client\Spi\NuxeoClientException
   */
  public function testCannotLoadBlob() {
    $client = $this->getClient()
      ->addResponse($this->createResponse());

    $client->automation('Blob.Attach')
      ->input(Blob::fromFile('/void', null))
      ->execute(Blob::class);

    $this->assertCount(0, $client->getRequests());
  }

  public function testLoadBlob() {
    $client = $this->getClient()
      ->addResponse($this->createResponse());

    $mimeType = 'text/plain';
    $client->automation('Blob.AttachOnDocument')
      ->param('document', self::DOC_PATH)
      ->input(Blob::fromFile($this->getResource('myfile.txt'), $mimeType))
      ->execute(Blob::class);

    $request = $client->getRequest();
    $requestBody = $request->getBody()->getContents();

    $this->assertRequestPathMatches($client, 'automation/Blob.AttachOnDocument');
    $this->assertArrayHasKey('content-type', $request->getHeaders());

    $this->assertStringMatchesFormat(
      'multipart/related;boundary=%s',
      $request->getHeader('content-type')[0]);

    $this->assertStringMatchesFormatFile($this->getResource('setblob.txt'),
      preg_replace('/\r\n/', "\n", $requestBody));

    $this->assertContains('content-type: '.$mimeType, $requestBody, '', true);

  }

  public function testDirectoryEntries() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('directory-entries.json'))));

    $continents = $client->automation('Directory.Entries')
      ->param('directoryName', 'continent')
      ->execute(Operation\DirectoryEntries::class);

    $this->assertRequestPathMatches($client, 'automation/Directory.Entries');
    $this->assertInstanceOf(Operation\DirectoryEntries::class, $continents);
    $this->assertCount(7, $continents);

    $ids = array('id001', 'id002', 'id003', 'id004');
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(json_encode($ids)));

    $continents = $client->automation('Directory.CreateEntries')
      ->param('directoryName', 'continent')
      ->param('entries', $client->getConverter()->writeJSON(Operation\DirectoryEntries::fromArray(array(
        array('id' => 'id001', 'label' => 'label.continent.one'),
        array('id' => 'id002', 'label' => 'label.continent.two', 'ordering' => 42),
        array('id' => 'id003', 'label' => 'label.continent.three', 'obsolete' => 1),
        array('id' => 'id004', 'label' => 'label.continent.four', 'ordering' => 666, 'obsolete' => 5),
      ))))
      ->execute();

    $this->assertCount(2, $requests = $client->getRequests());

    $this->assertRequestPathMatches($client, 'automation/Directory.CreateEntries', 1);
    $this->assertNotNull($decoded = json_decode((string) $client->getRequest(1)->getBody()->getContents(), true));
    $this->assertTrue(!empty($decoded['params']['entries']) && is_string($decoded['params']['entries']));
    $this->assertTrue(null !== ($entries = json_decode($decoded['params']['entries'], true)) && !empty($entries[0]['id']));
    $this->assertEquals('id001', $entries[0]['id']);
    $this->assertEquals('label.continent.one', $entries[0]['label']);
    $this->assertEquals(42, $entries[1]['ordering']);
    $this->assertEquals(5, $entries[3]['obsolete']);
    $this->assertEquals($ids, $continents);
  }

  public function testCounters() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('counters.json'))));
    $counterName = 'org.nuxeo.web.sessions';

    $counters = $client->automation('Counters.GET')
      ->param('counterNames', $counterName)
      ->execute(Operation\CounterList::class);

    $this->assertRequestPathMatches($client, 'automation/Counters.GET');
    $this->assertInstanceOf(Operation\CounterList::class, $counters);
    $this->assertCount(1, $counters);

    $this->assertCount(0, $counters[$counterName]->getSpeed());
    $this->assertCount(1, $counters[$counterName]->getDeltas());
    $this->assertCount(1, $counterValues = $counters[$counterName]->getValues());

    $this->assertNotEmpty($counterValues[0]->getTimestamp());
    $this->assertNotEmpty($counterValues[0]->getValue());
  }

  public function testAuditQuery() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('audit-query.json'))));

    $entries = $client->automation('Audit.Query')
      ->param('query', 'from LogEntry')
      ->execute(Operation\LogEntries::class);

    $this->assertRequestPathMatches($client, 'automation/Audit.Query');
    $this->assertInstanceOf(Operation\LogEntries::class, $entries);
    $this->assertCount(2, $entries);

    /** @var LogEntry $entry */
    $this->assertInstanceOf(LogEntry::class, $entry = $entries[0]);
    $this->assertNotEmpty($entry->getCategory());
    $this->assertNotEmpty($entry->getDocLifeCycle());
    $this->assertNotEmpty($entry->getDocPath());
    $this->assertNotEmpty($entry->getDocType());
    $this->assertNotEmpty($entry->getDocUUID());
    $this->assertNotEmpty($entry->getEventDate());
    $this->assertNotEmpty($entry->getEventId());
    $this->assertNotEmpty($entry->getPrincipalName());
    $this->assertNotEmpty($entry->getRepositoryId());

    $this->assertInstanceOf(LogEntry::class, $entry = $entries[1]);
    $this->assertNotEmpty($entry->getComment());
  }

  public function testActionsGet() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('actions-get.json'))));

    $actions = $client->automation('Actions.GET')
      ->param('category', 'VIEW_ACTION_LIST')
      ->input(new Operation\DocRef(self::DOC_PATH))
      ->execute(Operation\ActionList::class);

    $this->assertRegExp(sprintf(',doc:%s,', self::DOC_PATH), (string) $client->getRequest()->getBody());

    $this->assertRequestPathMatches($client, 'automation/Actions.GET');
    $this->assertInstanceOf(Operation\ActionList::class, $actions);
    $this->assertCount(8, $actions);

    /** @var Operation\Action $action */
    $this->assertInstanceOf(Operation\Action::class, $action = $actions[0]);
    $this->assertNotEmpty($action->getId());
    $this->assertNotEmpty($action->getLink());
    $this->assertNotEmpty($action->getIcon());
    $this->assertNotEmpty($action->getLabel());
    $this->assertNotNull($action->getHelp());
  }

  public function testGroupSuggest() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('usergroup-suggest.json'))));

    $groups = $client->automation('UserGroup.Suggestion')
      ->execute(Operation\UserGroupList::class);

    $this->assertRequestPathMatches($client, 'automation/UserGroup.Suggestion');
    $this->assertInstanceOf(Operation\UserGroupList::class, $groups);
    $this->assertCount(4, $groups);

    /** @var Operation\UserGroup $group */
    $this->assertInstanceOf(Operation\UserGroup::class, $group = $groups[0]);
    $this->assertNotEmpty($group->getEmail());
    $this->assertNotEmpty($group->getUsername());
    $this->assertNotEmpty($group->getId());
    $this->assertNotEmpty($group->getPrefixedId());
    $this->assertNotEmpty($group->getDisplayLabel());
    $this->assertEquals(Operation\UserGroup::USER_TYPE, $group->getType());

    $this->assertInstanceOf(Operation\UserGroup::class, $group = $groups[1]);
    $this->assertNotEmpty($group->getDescription());
    $this->assertNotEmpty($group->getGroupLabel());
    $this->assertNotEmpty($group->getGroupName());
    $this->assertEquals(Operation\UserGroup::GROUP_TYPE, $group->getType());
  }

}
