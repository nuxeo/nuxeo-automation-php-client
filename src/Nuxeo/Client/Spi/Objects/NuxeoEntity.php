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

namespace Nuxeo\Client\Spi\Objects;


use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use JMS\Serializer\Annotation as Serializer;
use Nuxeo\Client\Constants;
use Nuxeo\Client\Marshaller;
use Nuxeo\Client\NuxeoClient;
use Nuxeo\Client\Objects\Blob\Blob;
use Nuxeo\Client\Objects\Blob\Blobs;
use Nuxeo\Client\Objects\Operation;
use Nuxeo\Client\Request;
use Nuxeo\Client\Spi\ClassCastException;
use Nuxeo\Client\Spi\Http\Method\AbstractMethod;
use Nuxeo\Client\Spi\NuxeoClientException;
use Nuxeo\Client\Spi\NuxeoException;
use Nuxeo\Client\Util\HttpUtils;
use Psr\Http\Message\UriInterface;
use function GuzzleHttp\Psr7\stream_for;

abstract class NuxeoEntity extends AbstractConnectable {

  /**
   * @var NuxeoClient
   * @Serializer\Exclude()
   */
  private $nuxeoClient;

  /**
   * @Serializer\SerializedName("entity-type")
   * @Serializer\Type("string")
   */
  private $entityType;

  /**
   * @var string
   * @Serializer\SerializedName("repository")
   * @Serializer\Type("string")
   */
  private $repositoryName;

  /**
   * @var Marshaller\NuxeoConverter
   * @Serializer\Exclude()
   */
  private $converter;

  /**
   * @var Reader
   * @Serializer\Exclude()
   */
  private $annotationReader;

  /**
   * NuxeoEntity constructor.
   * @param $entityType
   * @param NuxeoClient $nuxeoClient
   */
  public function __construct($entityType, $nuxeoClient = null) {
    parent::__construct($nuxeoClient);

    $this->nuxeoClient = $nuxeoClient;
    $this->entityType = $entityType;
  }

  /**
   * @return string
   */
  public function getEntityType() {
    return $this->entityType;
  }

  /**
   * @return string
   */
  public function getRepositoryName() {
    return $this->repositoryName;
  }

  /**
   * @return self
   *
   * @throws AnnotationException
   */
  protected function setupDefaultMarshallers() {
    $this->getConverter()->registerMarshaller(Blob::class, new Marshaller\BlobMarshaller());
    $this->getConverter()->registerMarshaller(Blobs::class, new Marshaller\BlobsMarshaller());
    $this->getConverter()->registerMarshaller(Operation\ActionList::class, new Marshaller\ActionListMarshaller());
    $this->getConverter()->registerMarshaller(Operation\CounterList::class, new Marshaller\CounterListMarshaller());
    $this->getConverter()->registerMarshaller(Operation\CounterTimestampedValue::class, new Marshaller\CounterTimestampedValueMarshaller());
    $this->getConverter()->registerMarshaller(Operation\DirectoryEntries::class, new Marshaller\DirectoryEntriesMarshaller());
    $this->getConverter()->registerMarshaller(Operation\DocRef::class, new Marshaller\DocRefMarshaller($this->getNuxeoClient()));
    $this->getConverter()->registerMarshaller(Operation\LogEntries::class, new Marshaller\LogEntriesMarshaller());
    $this->getConverter()->registerMarshaller(Operation\UserGroupList::class, new Marshaller\UserGroupListMarshaller());
    $this->getConverter()->registerMarshaller(NuxeoException::class, new Marshaller\NuxeoExceptionMarshaller());
    return $this;
  }

  /**
   * @return Marshaller\NuxeoConverter
   * @throws AnnotationException
   */
  public function getConverter() {
    if (null === $this->converter) {
      $this->converter = new Marshaller\NuxeoConverter($this->getAnnotationReader());
      $this->setupDefaultMarshallers();
    }
    return $this->converter;
  }

  /**
   * @return Reader
   * @throws AnnotationException
   */
  public function getAnnotationReader() {
    if (null === $this->annotationReader) {
      $this->annotationReader = new AnnotationReader();
    }
    return $this->annotationReader;
  }

  /**
   * @return NuxeoClient
   */
  public function getNuxeoClient() {
    return $this->nuxeoClient;
  }

  /**
   * @param AbstractMethod $method
   * @param null $type
   * @return null
   * @throws \Nuxeo\Client\Spi\NuxeoClientException
   * @throws \Nuxeo\Client\Spi\ClassCastException
   */
  protected function getResponseNew(AbstractMethod $method, $type = null) {
    $body = $method->getBody();
    $files = $method->getFiles();

    try {
      [, $params] = $this->getCall();
    } catch (\ReflectionException $e) {
      throw NuxeoClientException::fromPrevious($e);
    }

    $request = $this->getRequest($method, $params);

    if (is_array($files)) {
      foreach ($files as $file) {
        $request = $request->addRelatedFile($file);
      }
    }

    try {
      if (null !== $body) {
        if (!is_string($body)) {
          $body = $this->getConverter()->writeJSON($body);
        }
        $request = $request->withBody(stream_for($body));
      }

      $response = $this->perform($request);

      if ($response->getBody()->getSize() > 0) {
        if (false === HttpUtils::isContentType($response, Constants::CONTENT_TYPE_JSON)) {

          switch ($type) {
            case Blobs::class:
              return Blobs::fromHttpResponse($response);
            case Blob::class:
              return Blob::fromHttpResponse($response);
            default:
              throw new ClassCastException(sprintf('Cannot cast %s as any of [%s]', implode(', ', [Blobs::class, Blob::class]), $type));
          }
        }
        $body = $this->getConverter()->readJSON((string)$response->getBody(), $type);

        if ($body instanceof self) {
          $body->reconnectWith($this->nuxeoClient);
        }

        return $body;
      }
    } catch (BadResponseException $e) {
      $response = $e->getResponse();
      $responseBody = (string)$response->getBody();
      if (empty($responseBody)) {
        throw new NuxeoClientException($response->getReasonPhrase(), $response->getStatusCode());
      }

      if (!HttpUtils::isContentType($response, Constants::CONTENT_TYPE_JSON)) {
        throw new NuxeoClientException($responseBody, $response->getStatusCode());
      }

      try {
        throw NuxeoClientException::fromPrevious(
          $this->getConverter()->readJSON($responseBody, NuxeoException::class),
          $response->getReasonPhrase(),
          $response->getStatusCode()
        );
      } catch (AnnotationException $e) {
        throw new NuxeoClientException($responseBody, $response->getStatusCode());
      }
    } catch (GuzzleException | AnnotationException $e) {
      throw NuxeoClientException::fromPrevious($e);
    }
    return null;
  }

  /**
   * @return array
   * @throws \ReflectionException
   */
  protected function getCall() {
    $backtrace = debug_backtrace();
    $reflectionClass = new \ReflectionClass($backtrace[2]['class']);
    $reflectionMethod = $reflectionClass->getMethod($backtrace[2]['function']);

    $params = array();
    $paramIndex = 0;
    $paramValues = $backtrace[2]['args'];
    $paramNames = array_map(function ($parameter) {
      /** @var \ReflectionParameter $parameter */
      return array($parameter->name, $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null);
    }, $reflectionMethod->getParameters());

    foreach ($paramNames as [$name, $default]) {
      $params[$name] = $paramValues[$paramIndex] ?? $default;
      $paramIndex++;
    }

    return array($reflectionMethod, $params);
  }

  /**
   * @param AbstractMethod $method
   * @param $params
   * @return Request
   * @throws NuxeoClientException
   */
  protected function getRequest(AbstractMethod $method, $params) {
    try {
      $request = $this->getNuxeoClient()->createRequest(
        $method->getName(),
        $this->computeRequestUrl($method->computePath($params))
      );

      return $request;
    } catch (\InvalidArgumentException $e) {
      throw NuxeoClientException::fromPrevious($e);
    }
  }

  /**
   * @param string $path
   * @return UriInterface
   */
  protected function computeRequestUrl($path) {
    return UriResolver::resolve($this->getNuxeoClient()->getApiUrl(), new Uri($path));
  }

  /**
   * @param $nuxeoClient
   * @return self
   */
  protected function reconnectWith($nuxeoClient) {
    parent::reconnectWith($nuxeoClient);
    $this->nuxeoClient = $nuxeoClient;

    return $this;
  }

}
