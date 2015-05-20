<?php
namespace TYPO3\CMS\Core\Http;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class ServerRequestFactory to create ServerRequest objects
 *
 * Highly inspired by https://github.com/phly/http/
 *
 * @internal Note that this is not public API yet.
 */
class ServerRequestFactory {

	/**
	 * Create a request from the original superglobal variables.
	 *
	 * @return ServerRequest
	 * @throws \InvalidArgumentException when invalid file values given
	 * @internal Note that this is not public API yet.
	 */
	static public function fromGlobals() {
		$serverParameters = $_SERVER;
		$headers = static::prepareHeaders($serverParameters);

		$method = isset($serverParameters['REQUEST_METHOD']) ? $serverParameters['REQUEST_METHOD'] : 'GET';
		$uri = new Uri(GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'));

		$request = new ServerRequest(
			$uri,
			$method,
			'php://input',
			$headers,
			$serverParameters,
			static::normalizeUploadedFiles($_FILES)
		);

		if (!empty($_COOKIE)) {
			$request = $request->withCookieParams($_COOKIE);
		}
		$queryParameters = GeneralUtility::_GET();
		if (!empty($queryParameters)) {
			$request = $request->withQueryParams($queryParameters);
		}
		$parsedBody = GeneralUtility::_POST();
		if (!empty($parsedBody)) {
			$request = $request->withParsedBody($parsedBody);
		}
		return $request;
	}

	/**
	 * Fetch headers from $_SERVER variables
	 * which are only the ones starting with HTTP_* and CONTENT_*
	 *
	 * @param array $server
	 * @return array
	 */
	protected static function prepareHeaders(array $server) {
		$headers = array();
		foreach ($server as $key => $value) {
			if (strpos($key, 'HTTP_COOKIE') === 0) {
				// Cookies are handled using the $_COOKIE superglobal
				continue;
			}
			if (!empty($value)) {
				if (strpos($key, 'HTTP_') === 0) {
					$name = strtr(substr($key, 5), '_', ' ');
					$name = strtr(ucwords(strtolower($name)), ' ', '-');
					$name = strtolower($name);
					$headers[$name] = $value;
				} elseif (strpos($key, 'CONTENT_') === 0) {
					$name = substr($key, 8); // Content-
					$name = 'Content-' . (($name == 'MD5') ? $name : ucfirst(strtolower($name)));
					$name = strtolower($name);
					$headers[$name] = $value;
				}
			}
		}
		return $headers;
	}

	/**
	 * Normalize uploaded files
	 *
	 * Transforms each value into an UploadedFileInterface instance, and ensures that nested arrays are normalized.
	 *
	 * @param array $files
	 * @return array
	 * @throws \InvalidArgumentException for unrecognized values
	 */
	protected static function normalizeUploadedFiles(array $files) {
		$normalizedFileUploads = array();
		foreach ($files as $key => $value) {
			if ($value instanceof UploadedFileInterface) {
				$normalizedFileUploads[$key] = $value;
			} elseif (is_array($value)) {
				if (isset($value['tmp_name'])) {
					$normalizedFileUploads[$key] = self::createUploadedFile($value);
				} else {
					$normalizedFileUploads[$key] = self::normalizeUploadedFiles($value);
				}
			} else {
				throw new \InvalidArgumentException('Invalid value in files specification.', 1436717282);
			}
		}
		return $normalizedFileUploads;
	}

	/**
	 * Create and return an UploadedFile instance from a $_FILES specification.
	 *
	 * If the specification represents an array of values, this method will
	 * delegate to normalizeNestedFileSpec() and return that return value.
	 *
	 * @param array $value $_FILES structure
	 * @return UploadedFileInterface[]|UploadedFileInterface
	 */
	protected static function createUploadedFile(array $value) {
		if (is_array($value['tmp_name'])) {
			$files = array();
			foreach (array_keys($value['tmp_name']) as $key) {
				$data = array(
					'tmp_name' => $value['tmp_name'][$key],
					'size'     => $value['size'][$key],
					'error'    => $value['error'][$key],
					'name'     => $value['name'][$key],
					'type'     => $value['type'][$key]
				);
				$files[$key] = self::createUploadedFile($data);
			}
			return $files;
		} else {
			return new UploadedFile($value['tmp_name'], $value['size'], $value['error'], $value['name'], $value['type']);
		}
	}

}
