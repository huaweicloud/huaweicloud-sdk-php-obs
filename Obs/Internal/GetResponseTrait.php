<?php

/**
 * Copyright 2019 Huawei Technologies Co.,Ltd.
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use
 * this file except in compliance with the License.  You may obtain a copy of the
 * License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed
 * under the License is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR
 * CONDITIONS OF ANY KIND, either express or implied.  See the License for the
 * specific language governing permissions and limitations under the License.
 *
 */

namespace Obs\Internal;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Obs\Internal\Common\CheckoutStream;
use Obs\Internal\Common\Model;
use Obs\Internal\Resource\Constants;
use Obs\Log\ObsLog;
use Obs\ObsException;
use Psr\Http\Message\StreamInterface;

trait GetResponseTrait
{
    protected $exceptionResponseMode = true;
    protected $chunkSize = 65536;

    protected function isClientError(Response $response)
    {
        return $response->getStatusCode() >= 400 && $response->getStatusCode() < 500;
    }

    protected function parseXmlByType($searchPath, $key, &$value, $xml, $prefix)
    {
        $type = 'string';

        if (isset($value['sentAs'])) {
            $key = $value['sentAs'];
        }

        if ($searchPath === null) {
            $searchPath = '//' . $prefix . $key;
        }

        if (isset($value['type'])) {
            $type = $value['type'];
            if ($type === 'array') {
                $items = $value['items'];
                if (isset($value['wrapper'])) {
                    $paths = explode('/', $searchPath);
                    $size = count($paths);
                    if ($size > 1) {
                        $end = $paths[$size - 1];
                        $paths[$size - 1] = $value['wrapper'];
                        $paths[] = $end;
                        $searchPath = implode('/', $paths) . '/' . $prefix;
                    }
                }

                $array = [];
                if (!isset($value['data']['xmlFlattened'])) {
                    $pkey = isset($items['sentAs']) ? $items['sentAs'] : $items['name'];
                    $newSearchPath = $searchPath . '/' . $prefix . $pkey;
                } else {
                    $pkey = $key;
                    $newSearchPath = $searchPath;
                }
                $result = $xml->xpath($newSearchPath);
                if ($result && is_array($result)) {
                    foreach ($result as $subXml) {
                        $subXml = simplexml_load_string($subXml->asXML());
                        $subPrefix = $this->getXpathPrefix($subXml);
                        $array[] = $this->parseXmlByType(
                            '//' . $subPrefix . $pkey,
                            $pkey,
                            $items,
                            $subXml,
                            $subPrefix
                        );
                    }
                }
                return $array;
            } elseif ($type === 'object') {
                $properties = $value['properties'];
                $array = [];
                foreach ($properties as $pkey => $pvalue) {
                    $name = isset($pvalue['sentAs']) ? $pvalue['sentAs'] : $pkey;
                    $array[$pkey] = $this->parseXmlByType(
                        $searchPath . '/' . $prefix . $name,
                        $name,
                        $pvalue,
                        $xml,
                        $prefix
                    );
                }
                return $array;
            } else {
                // nothing handle
            }
        }

        if ($result = $xml->xpath($searchPath)) {
            if ($type === 'boolean') {
                return strval($result[0]) !== 'false';
            } elseif ($type === 'numeric' || $type === 'float') {
                return floatval($result[0]);
            } elseif ($type === 'int' || $type === 'integer') {
                return intval($result[0]);
            } else {
                return strval($result[0]);
            }
        } else {
            if ($type === 'boolean') {
                return false;
            } elseif ($type === 'numeric' || $type === 'float' || $type === 'int' || $type === 'integer') {
                return null;
            } else {
                return '';
            }
        }
    }

    private function isJsonResponse($response)
    {
        return $response->getHeaderLine('content-type') === 'application/json';
    }

    private function parseCommonHeaders($model, $response)
    {
        $constants = Constants::selectConstants($this->signature);
        foreach ($constants::COMMON_HEADERS as $key => $value) {
            $model[$value] = $response->getHeaderLine($key);
        }
    }

    protected function parseItems($responseParameters, $model, $response, $body)
    {
        $prefix = '';

        $this->parseCommonHeaders($model, $response);

        $closeBody = false;
        try {
            foreach ($responseParameters as $key => $value) {
                if (isset($value['location'])) {
                    $location = $value['location'];
                    if ($location === 'header') {
                        $name = isset($value['sentAs']) ? $value['sentAs'] : $key;
                        $isSet = false;
                        if (isset($value['type'])) {
                            $type = $value['type'];
                            if ($type === 'object') {
                                $headers = $response->getHeaders();
                                $temp = [];
                                foreach ($headers as $headerName => $headerValue) {
                                    if (stripos($headerName, $name) === 0) {
                                        $metaKey = rawurldecode(substr($headerName, strlen($name)));
                                        $temp[$metaKey] = rawurldecode($response->getHeaderLine($headerName));
                                    }
                                }
                                $model[$key] = $temp;
                                $isSet = true;
                            } else {
                                if ($response->hasHeader($name)) {
                                    if ($type === 'boolean') {
                                        $model[$key] = ($response->getHeaderLine($name)) !== 'false';
                                        $isSet = true;
                                    } elseif ($type === 'numeric' || $type === 'float') {
                                        $model[$key] = floatval($response->getHeaderLine($name));
                                        $isSet = true;
                                    } elseif ($type === 'int' || $type === 'integer') {
                                        $model[$key] = intval($response->getHeaderLine($name));
                                        $isSet = true;
                                    } else {
                                        // nothing handle
                                    }
                                }
                            }
                        }
                        if (!$isSet) {
                            $model[$key] = rawurldecode($response->getHeaderLine($name));
                        }
                    } elseif ($location === 'xml' && $body !== null) {
                        if (!isset($xml)) {
                            $xml = simplexml_load_string($body->getContents());
                            if ($xml) {
                                $prefix = $this->getXpathPrefix($xml);
                            }
                        }
                        $closeBody = true;
                        $model[$key] = $this->parseXmlByType(null, $key, $value, $xml, $prefix);
                    } elseif ($location === 'body' && $body !== null) {
                        if (isset($value['type']) && $value['type'] === 'stream') {
                            $model[$key] = $body;
                        } elseif (isset($value['type']) && $value['type'] === 'json') {
                            $jsonBody = trim($body->getContents());
                            $data = json_decode($jsonBody, true);
                            if ($jsonBody && $data) {
                                if (is_array($data)) {
                                    $model[$key] = $data;
                                } elseif (strlen($data)) {
                                    ObsLog::error(
                                        "response body %s, and jsonToArray data is %s",
                                        $body,
                                        $data
                                    );
                                } else {
                                    // nothing handle
                                }
                            }
                            $closeBody = true;
                        } else {
                            $model[$key] = $body->getContents();
                            $closeBody = true;
                        }
                    } else {
                        // nothing handle
                    }
                }
            }
        } finally {
            if ($closeBody && $body !== null) {
                $body->close();
            }
        }
    }

    private function writeFile($filePath, StreamInterface &$body)
    {
        $filePath = iconv('UTF-8', 'GBK', $filePath);
        if (is_string($filePath) && $filePath !== '') {
            $fp = null;
            $dir = dirname($filePath);
            try {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                if ($fp = fopen($filePath, 'w')) {
                    while (!$body->eof()) {
                        $str = $body->read($this->chunkSize);
                        fwrite($fp, $str);
                    }
                    fflush($fp);
                    ObsLog::debug("write file %s ok", $filePath);
                } else {
                    ObsLog::error("open file error,file path:%s", $filePath);
                }
            } finally {
                if ($fp) {
                    fclose($fp);
                }
                $body->close();
                $body = null;
            }
        }
    }

    private function parseXmlToException($body, $obsException)
    {
        try {
            $xmlErrorBody = trim($body->getContents());
            if ($xmlErrorBody) {
                $xml = simplexml_load_string($xmlErrorBody);
                if ($xml) {
                    $prefix = $this->getXpathPrefix($xml);
                    if ($tempXml = $xml->xpath('//' . $prefix . 'Code')) {
                        $obsException->setExceptionCode(strval($tempXml[0]));
                    }
                    if ($tempXml = $xml->xpath('//' . $prefix . 'RequestId')) {
                        $obsException->setRequestId(strval($tempXml[0]));
                    }
                    if ($tempXml = $xml->xpath('//' . $prefix . 'Message')) {
                        $obsException->setExceptionMessage(strval($tempXml[0]));
                    }
                    if ($tempXml = $xml->xpath('//' . $prefix . 'HostId')) {
                        $obsException->setHostId(strval($tempXml[0]));
                    }
                }
            }
        } finally {
            $body->close();
        }
    }

    private function parseJsonToException($body, $obsException)
    {
        try {
            $jsonErrorBody = trim($body->getContents());
            if ($jsonErrorBody) {
                $data = json_decode($jsonErrorBody, true);
                if ($data && is_array($data)) {
                    if ($data['request_id']) {
                        $obsException->setRequestId(strval($data['request_id']));
                    }
                    if ($data['code']) {
                        $obsException->setExceptionCode(strval($data['code']));
                    }
                    if ($data['message']) {
                        $obsException->setExceptionMessage(strval($data['message']));
                    }
                } elseif ($data && strlen($data)) {
                    ObsLog::error("response body %s, and jsonToArray data is %s", $body, $data);
                    $obsException->setExceptionMessage("Invalid response data，since it is not json data");
                } else {
                    // nothing handle
                }
            }
        } finally {
            $body->close();
        }
    }

    private function parseJsonToModel($body, $model)
    {
        try {
            $jsonErrorBody = trim($body->getContents());
            if ($jsonErrorBody) {
                $jsonArray = json_decode($jsonErrorBody, true);
                if ($jsonArray && is_array($jsonArray)) {
                    if ($jsonArray['request_id']) {
                        $model['RequestId'] = strval($jsonArray['request_id']);
                    }
                    if ($jsonArray['code']) {
                        $model['Code'] = strval($jsonArray['code']);
                    }
                    if ($jsonArray['message']) {
                        $model['Message'] = strval($jsonArray['message']);
                    }
                } elseif ($jsonArray && strlen($jsonArray)) {
                    ObsLog::error("response body %s, and jsonToArray data is %s", $body, $jsonArray);
                    $model['Message'] = "Invalid response data，since it is not json data";
                } else {
                    // nothing handle
                }
            }
        } finally {
            $body->close();
        }
    }

    private function parseXmlToModel($body, $model)
    {
        try {
            $xmlErrorBody = trim($body->getContents());
            $xml = simplexml_load_string($xmlErrorBody);
            if ($xmlErrorBody && $xml) {
                $prefix = $this->getXpathPrefix($xml);
                if ($tempXml = $xml->xpath('//' . $prefix . 'Code')) {
                    $model['Code'] = strval($tempXml[0]);
                }
                if ($tempXml = $xml->xpath('//' . $prefix . 'RequestId')) {
                    $model['RequestId'] = strval($tempXml[0]);
                }

                if ($tempXml = $xml->xpath('//' . $prefix . 'HostId')) {
                    $model['HostId'] = strval($tempXml[0]);
                }
                if ($tempXml = $xml->xpath('//' . $prefix . 'Resource')) {
                    $model['Resource'] = strval($tempXml[0]);
                }

                if ($tempXml = $xml->xpath('//' . $prefix . 'Message')) {
                    $model['Message'] = strval($tempXml[0]);
                }
            }
        } finally {
            $body->close();
        }
    }

    protected function parseResponse(Model $model, Request $request, Response $response, array $requestConfig)
    {
        $statusCode = $response->getStatusCode();
        $expectedLength = $response->getHeaderLine('content-length');
        $responseContentType = $response->getHeaderLine('content-type');

        $expectedLength = is_numeric($expectedLength) ? floatval($expectedLength) : null;

        $body = new CheckoutStream($response->getBody(), $expectedLength);

        if ($statusCode >= 300) {
            if ($this->exceptionResponseMode) {
                $obsException = new ObsException();
                $obsException->setRequest($request);
                $obsException->setResponse($response);
                $obsException->setExceptionType($this->isClientError($response) ? 'client' : 'server');
                if ($responseContentType === 'application/json') {
                    $this->parseJsonToException($body, $obsException);
                } else {
                    $this->parseXmlToException($body, $obsException);
                }
                throw $obsException;
            } else {
                $this->parseCommonHeaders($model, $response);
                if ($responseContentType === 'application/json') {
                    $this->parseJsonToModel($body, $model);
                } else {
                    $this->parseXmlToModel($body, $model);
                }
            }
        } else {
            if (!empty($model)) {
                foreach ($model as $key => $value) {
                    if ($key === 'method') {
                        continue;
                    }
                    if (isset($value['type']) && $value['type'] === 'file') {
                        $this->writeFile($value['value'], $body);
                    }
                    $model[$key] = $value['value'];
                }
            }

            if (isset($requestConfig['responseParameters'])) {
                $responseParameters = $requestConfig['responseParameters'];
                if (isset($responseParameters['type']) && $responseParameters['type'] === 'object') {
                    $responseParameters = $responseParameters['properties'];
                }
                $this->parseItems($responseParameters, $model, $response, $body);
            }
        }

        $model['HttpStatusCode'] = $statusCode;
        $model['Reason'] = $response->getReasonPhrase();
    }

    protected function getXpathPrefix($xml)
    {
        $namespaces = $xml->getDocNamespaces();
        if (isset($namespaces[''])) {
            $xml->registerXPathNamespace('ns', $namespaces['']);
            $prefix = 'ns:';
        } else {
            $prefix = '';
        }
        return $prefix;
    }

    protected function buildException(Request $request, RequestException $exception, $message)
    {
        $response = $exception->hasResponse() ? $exception->getResponse() : null;
        $obsException = new ObsException($message ? $message : $exception->getMessage());
        $obsException->setExceptionType('client');
        $obsException->setRequest($request);
        if ($response) {
            $obsException->setResponse($response);
            $obsException->setExceptionType($this->isClientError($response) ? 'client' : 'server');
            if ($this->isJsonResponse($response)) {
                $this->parseJsonToException($response->getBody(), $obsException);
            } else {
                $this->parseXmlToException($response->getBody(), $obsException);
            }
            if ($obsException->getRequestId() === null) {
                $prefix = strcasecmp($this->signature, 'obs') === 0 ? 'x-obs-' : 'x-amz-';
                $requestId = $response->getHeaderLine($prefix . 'request-id');
                $obsException->setRequestId($requestId);
            }
        }
        return $obsException;
    }

    protected function parseExceptionAsync(Request $request, RequestException $exception, $message = null)
    {
        return $this->buildException($request, $exception, $message);
    }

    protected function parseException(Model $model, Request $request, RequestException $exception, $message = null)
    {
        $response = $exception->hasResponse() ? $exception->getResponse() : null;
        if ($this->exceptionResponseMode) {
            throw $this->buildException($request, $exception, $message);
        } else {
            if ($response) {
                $model['HttpStatusCode'] = $response->getStatusCode();
                $model['Reason'] = $response->getReasonPhrase();
                $this->parseXmlToModel($response->getBody(), $model);
            } else {
                $model['HttpStatusCode'] = -1;
                $model['Message'] = $exception->getMessage();
            }
        }
    }
}