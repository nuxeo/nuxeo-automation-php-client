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


use Nuxeo\Client\Constants;
use Nuxeo\Client\Objects\Audit\LogEntry;
use Nuxeo\Client\Objects\Blob\Blob;
use Nuxeo\Client\Objects\Blob\Blobs;
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
      ->schemas('invalid')
      ->schemas('dublincore')
      ->automation()
      ->schemas('note', true)
      ->param('query', 'SELECT * FROM Document')
      ->execute(null, 'Document.Query');

    self::assertRequestPathMatches($client, 'automation/Document.Query');
    self::assertInstanceOf(Documents::class, $documents);
    self::assertEquals(5, $documents->getCurrentPageSize());

    self::assertEquals('dublincore, note', $client->getRequest()->getHeaderLine(Constants::HEADER_PROPERTIES));

    foreach ($documents as $document) {
      self::assertNotEmpty($document->getUid());
      self::assertNotEmpty($document->getPath());
      self::assertNotEmpty($document->getType());
      self::assertNotEmpty($document->getState());
      self::assertNotEmpty($document->getTitle());
      self::assertNotEmpty($document->getProperty('dc:created'));
    }

    $note = $documents[0];
    self::assertNotNull($note);
    self::assertEquals(self::DOC_TYPE, $note->getType());
    self::assertEquals(self::DOC_TITLE, $note->getProperty('dc:title'));
    self::assertNull($note->getProperty('dc:nonexistent'));
  }

  public function testMyDocTypeDeserialize() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('document.json'))));

    /** @var MyDocType $document */
    $document = $client
      ->schemas(['dublincore', 'note'])
      ->automation('Document.Fetch')
      ->param('value', '0fa9d2a0-e69f-452d-87ff-0c5bd3b30d7d')
      ->execute(MyDocType::class);

    self::assertEquals('dublincore, note', $client->getRequest()->getHeaderLine(Constants::HEADER_PROPERTIES));
    self::assertRequestPathMatches($client, 'automation/Document.Fetch');
    self::assertInstanceOf(MyDocType::class, $document);
    self::assertEquals($document->getCreatedAt(), $document->getProperty('dc:created'));
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
    self::assertNotEmpty($doc->name);
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
    self::assertInstanceOf(Operation\DocRef::class, $docRef);
    self::assertInstanceOf(Document::class, $doc = $docRef->getDocument());
    self::assertNotEmpty($doc->getPath());
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

    self::assertRequestPathMatches($client, 'automation/Blob.Get');
    self::assertEquals(sprintf('{"params":{},"input":"%s"}', self::DOC_PATH), (string) $client->getRequest()->getBody());
    self::assertEquals($blob->getStream()->getContents(), self::DOC_CONTENT);
  }

  public function testListBlobs() {
    $boundary = 'my_boundary';
    $body = file_get_contents($this->getResource('blob.getlist.txt'))
      . file_get_contents($this->getResource(self::IMG_FS_PATH))
      . "--$boundary--";

    $client = $this->getClient()
      ->addResponse($this->createResponse(200, array(
        'Content-Type' => 'multipart/mixed; boundary="my_boundary"',
      ), $body));

    $blobs = $client->automation('Blob.GetList')
      ->input('/default-domain/workspaces/My Workspace/MyFile')
      ->execute(Blobs::class);

    self::assertCount(1, $blobs);
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

    self::assertCount(0, $client->getRequests());
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

    self::assertRequestPathMatches($client, 'automation/Blob.AttachOnDocument');
    self::assertArrayHasKey('content-type', $request->getHeaders());

    self::assertStringMatchesFormat(
      'multipart/related;boundary=%s',
      $request->getHeader('content-type')[0]);

    self::assertStringMatchesFormatFile($this->getResource('setblob.txt'),
      preg_replace('/\r\n/', "\n", $requestBody));

    self::assertContains('content-type: '.$mimeType, $requestBody, '', true);

  }

  public function testDirectoryEntries() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('directory-entries.json'))));

    $automation = $client->automation('Directory.Entries');
    $continents = $automation
      ->param('directoryName', 'continent')
      ->execute(Operation\DirectoryEntries::class);

    self::assertRequestPathMatches($client, 'automation/Directory.Entries');
    self::assertInstanceOf(Operation\DirectoryEntries::class, $continents);
    self::assertCount(7, $continents);

    $ids = array('id001', 'id002', 'id003', 'id004');
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(json_encode($ids)));

    $continents = $client->automation('Directory.CreateEntries')
      ->param('directoryName', 'continent')
      ->param('entries', $automation->getConverter()->writeJSON(Operation\DirectoryEntries::fromArray(array(
        array('id' => 'id001', 'label' => 'label.continent.one'),
        array('id' => 'id002', 'label' => 'label.continent.two', 'ordering' => 42),
        array('id' => 'id003', 'label' => 'label.continent.three', 'obsolete' => 1),
        array('id' => 'id004', 'label' => 'label.continent.four', 'ordering' => 666, 'obsolete' => 5),
      ))))
      ->execute();

    self::assertCount(2, $requests = $client->getRequests());

    self::assertRequestPathMatches($client, 'automation/Directory.CreateEntries', 1);
    self::assertNotNull($decoded = json_decode((string) $client->getRequest(1)->getBody()->getContents(), true));
    self::assertTrue(!empty($decoded['params']['entries']) && is_string($decoded['params']['entries']));
    self::assertTrue(null !== ($entries = json_decode($decoded['params']['entries'], true)) && !empty($entries[0]['id']));
    self::assertEquals('id001', $entries[0]['id']);
    self::assertEquals('label.continent.one', $entries[0]['label']);
    self::assertEquals(42, $entries[1]['ordering']);
    self::assertEquals(5, $entries[3]['obsolete']);
    self::assertEquals($ids, $continents);
  }

  public function testCounters() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('counters.json'))));
    $counterName = 'org.nuxeo.web.sessions';

    $counters = $client->automation('Counters.GET')
      ->param('counterNames', $counterName)
      ->execute(Operation\CounterList::class);

    self::assertRequestPathMatches($client, 'automation/Counters.GET');
    self::assertInstanceOf(Operation\CounterList::class, $counters);
    self::assertCount(1, $counters);

    self::assertCount(0, $counters[$counterName]->getSpeed());
    self::assertCount(1, $counters[$counterName]->getDeltas());
    self::assertCount(1, $counterValues = $counters[$counterName]->getValues());

    self::assertNotEmpty($counterValues[0]->getTimestamp());
    self::assertNotEmpty($counterValues[0]->getValue());
  }

  public function testAuditQuery() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('audit-query.json'))));

    $entries = $client->automation('Audit.Query')
      ->param('query', 'from LogEntry')
      ->execute(Operation\LogEntries::class);

    self::assertRequestPathMatches($client, 'automation/Audit.Query');
    self::assertInstanceOf(Operation\LogEntries::class, $entries);
    self::assertCount(2, $entries);

    /** @var LogEntry $entry */
    self::assertInstanceOf(LogEntry::class, $entry = $entries[0]);
    self::assertNotEmpty($entry->getCategory());
    self::assertNotEmpty($entry->getDocLifeCycle());
    self::assertNotEmpty($entry->getDocPath());
    self::assertNotEmpty($entry->getDocType());
    self::assertNotEmpty($entry->getDocUUID());
    self::assertNotEmpty($entry->getEventDate());
    self::assertNotEmpty($entry->getEventId());
    self::assertNotEmpty($entry->getPrincipalName());
    self::assertNotEmpty($entry->getRepositoryId());

    self::assertInstanceOf(LogEntry::class, $entry = $entries[1]);
    self::assertNotEmpty($entry->getComment());
  }

  public function testActionsGet() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('actions-get.json'))));

    $actions = $client->automation('Actions.GET')
      ->param('category', 'VIEW_ACTION_LIST')
      ->input(new Operation\DocRef(self::DOC_PATH))
      ->execute(Operation\ActionList::class);

    self::assertRegExp(sprintf(',doc:%s,', self::DOC_PATH), (string) $client->getRequest()->getBody());

    self::assertRequestPathMatches($client, 'automation/Actions.GET');
    self::assertInstanceOf(Operation\ActionList::class, $actions);
    self::assertCount(8, $actions);

    /** @var Operation\Action $action */
    self::assertInstanceOf(Operation\Action::class, $action = $actions[0]);
    self::assertNotEmpty($action->getId());
    self::assertNotEmpty($action->getLink());
    self::assertNotEmpty($action->getIcon());
    self::assertNotEmpty($action->getLabel());
    self::assertNotNull($action->getHelp());
  }

  public function testGroupSuggest() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponse(file_get_contents($this->getResource('usergroup-suggest.json'))));

    $groups = $client->automation('UserGroup.Suggestion')
      ->execute(Operation\UserGroupList::class);

    self::assertRequestPathMatches($client, 'automation/UserGroup.Suggestion');
    self::assertInstanceOf(Operation\UserGroupList::class, $groups);
    self::assertCount(4, $groups);

    /** @var Operation\UserGroup $group */
    self::assertInstanceOf(Operation\UserGroup::class, $group = $groups[0]);
    self::assertNotEmpty($group->getEmail());
    self::assertNotEmpty($group->getUsername());
    self::assertNotEmpty($group->getId());
    self::assertNotEmpty($group->getPrefixedId());
    self::assertNotEmpty($group->getDisplayLabel());
    self::assertEquals(Operation\UserGroup::USER_TYPE, $group->getType());

    self::assertInstanceOf(Operation\UserGroup::class, $group = $groups[1]);
    self::assertNotEmpty($group->getDescription());
    self::assertNotEmpty($group->getGroupLabel());
    self::assertNotEmpty($group->getGroupName());
    self::assertEquals(Operation\UserGroup::GROUP_TYPE, $group->getType());
  }

  public function testTrash() {
    $client = $this->getClient()
      ->addResponse($this->createJsonResponseFromFile('document.json'));

    (new Document($client))
      ->setUid(self::DOC_UID)
      ->untrash();

    self::assertRequestPathMatches($client, 'automation/Document.Untrash');
  }

}
