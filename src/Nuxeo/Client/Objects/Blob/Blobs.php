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

namespace Nuxeo\Client\Objects\Blob;

use GuzzleHttp\Psr7\Utils;
use JMS\Serializer\Annotation as Serializer;
use Laminas\Mail\Header\ContentDisposition;
use Laminas\Mail\Header\ContentType;
use Laminas\Mime\Decode;
use Nuxeo\Client\Response;
use Nuxeo\Client\Spi\Objects\NuxeoEntity;
use Riverline\MultiPartParser\StreamedPart;


class Blobs extends NuxeoEntity implements \Countable {

  /**
   * @var Blob[]
   * @Serializer\Exclude()
   */
  protected $blobs = array();

  /**
   * Blobs constructor.
   * @param Blob[] $blobs
   */
  public function __construct($blobs = array()) {
    parent::__construct(null);

    $this->blobs = $blobs;
  }

  /**
   * @return Blob[]
   */
  public function getBlobs() {
    return $this->blobs;
  }

  /**
   * @param Blob $blob
   */
  public function add($blob) {
    $this->blobs[] = $blob;
  }

  public function count() {
    return count($this->blobs);
  }

  /**
   * @param Response $response
   * @return Blobs
   */
  public static function fromHttpResponse($response) {
    $matches = [];
    $blobs = [];

    if(preg_match(',multipart/mixed; boundary="([^"]*)",', $response->getHeaderLine('content-type'), $matches)) {
      [,$boundary] = $matches;

      foreach (Decode::splitMessageStruct($response->getBody()->__toString(), $boundary) as $part) {
        /** @var ContentDisposition $disposition */
        $disposition = $part['header']->get('content-disposition');

        /** @var ContentType $mimetype */
        $mimetype = $part['header']->get('content-type');

        $blobs[] = new Blob($disposition->getParameter('filename'), Utils::streamFor($part['body']), $mimetype->getType());
      }
    } else {
      // raise exception should be multipart
    }

    return new Blobs($blobs);
  }
}
