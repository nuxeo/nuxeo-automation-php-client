<?php
/**
 * (C) Copyright 2018 Nuxeo SA (http://nuxeo.com/) and contributors.
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
 */

namespace Nuxeo\Client\FTests;


use Nuxeo\Client\Objects\Blob\Blob;
use Nuxeo\Client\Objects\Document;
use Nuxeo\Client\Objects\Documents;
use Nuxeo\Client\Objects\Operation\DirectoryEntries;
use Nuxeo\Client\FTests\Framework\TestCase;

/**
 * @group server
 */
class OperationTest extends TestCase {

  public function testListDocuments() {
    /** @var Documents $documents */
    $documents = $this->getClient()
      ->schemas('*')
      ->automation('Repository.Query')
      ->param('query', 'SELECT * FROM Document')
      ->execute(Documents::class);

    self::assertGreaterThan(5, count($documents));
  }

  public function testCreateDocument() {
    /** @var Document $doc */
    $doc = $this->getClient()
      ->automation('Document.Create')
      ->input('doc:/')
      ->params(array(
        'type' => 'File',
        'name' => 'Some file',
        'properties' => 'dc:title=Some file'
      ))->execute(Document::class);

    self::assertNotNull($doc->getUid());

    $this->getClient()
      ->automation('Blob.Attach')
      ->param('document', $doc->getPath())
      ->input(Blob::fromFile($this->getResource('nuxeo.png'), null))
      ->execute(Blob::class);

    $blob = $this->getClient()
      ->voidOperation(false)
      ->automation('Blob.Get')
      ->input($doc->getPath())
      ->execute(Blob::class);

    self::assertInstanceOf(Blob::class, $blob);
  }

  public function testBlobsGetList() {
    /** @var Document $doc */
    $doc = $this->getClient()
      ->automation('Document.Create')
      ->input('doc:/')
      ->params(array(
        'type' => 'File',
        'name' => 'Some file',
        'properties' => 'dc:title=Some file'
      ))->execute(Document::class);

    self::assertNotNull($doc->getUid());
  }

  public function testDirectories() {
    /** @var DirectoryEntries $continents */
    $continents = $this->getClient()
      ->automation('Directory.Entries')
      ->param('directoryName', 'continent')
      ->execute(DirectoryEntries::class);

    self::assertCount(7, $continents);
  }

}
