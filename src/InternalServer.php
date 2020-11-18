<?php

namespace donatj\MockWebServer;

use donatj\MockWebServer\Exceptions\ServerException;

/**
 * Class InternalServer
 *
 * @internal
 */
class InternalServer {

	const PATH_LIST_FILE = 'PATH_LIST.list';

	/**
	 * @var string
	 */
	private $tmpPath;
	/**
	 * @var \donatj\MockWebServer\RequestInfo
	 */
	private $request;
	/**
	 * @var callable
	 */
	private $header;

	/**
	 * InternalServer constructor.
	 *
	 * @param string                            $tmpPath
	 * @param \donatj\MockWebServer\RequestInfo $request
	 * @param callable|null                     $header
	 */
	public function __construct( $tmpPath, RequestInfo $request, callable $header = null ) {
		if( $header === null ) {
			$header = "\\header";
		}

		$this->tmpPath = $tmpPath;

		$count = self::incrementRequestCounter($this->tmpPath);
		$this->logRequest($request, $count);

		$this->header  = $header;
		$this->request = $request;
	}

	/**
	 * @param string   $tmpPath
	 * @param null|int $int
	 * @return int
	 */
	public static function incrementRequestCounter( $tmpPath, $int = null ) {
		$countFile = $tmpPath . DIRECTORY_SEPARATOR . MockWebServer::REQUEST_COUNT_FILE;

		if( $int === null ) {
			$newInt = file_get_contents($countFile);
			if( !is_string($newInt) ) {
				throw new ServerException('failed to fetch request count');
			}
			$int = (int)$newInt + 1;
		}

		file_put_contents($countFile, (string)$int);

		return (int)$int;
	}

	private function logRequest( RequestInfo $request, $count ) {
		$reqStr = serialize($request);
		file_put_contents($this->tmpPath . DIRECTORY_SEPARATOR . MockWebServer::LAST_REQUEST_FILE, $reqStr);
		file_put_contents($this->tmpPath . DIRECTORY_SEPARATOR . 'request.' . $count, $reqStr);
	}

	public static function aliasPath( $tmpPath, $path ) {
		$path = '/' . ltrim($path, '/');

		return sprintf('%s%salias.%s',
			$tmpPath,
			DIRECTORY_SEPARATOR,
			md5($path)
		);
	}

	public function __invoke() {
		$path = $this->getDataPath();

		if( $path !== false ) {
			if( is_readable($path) ) {
				$content  = file_get_contents($path);
				$response = unserialize($content);
				if( !$response instanceof ResponseInterface ) {
					throw new ServerException('invalid serialized response');
				}

				http_response_code($response->getStatus($this->request));

				foreach( $response->getHeaders($this->request) as $key => $header ) {
					if( is_int($key) ) {
						call_user_func($this->header, $header);
					} else {
						call_user_func($this->header, "{$key}: {$header}");
					}
				}
				$body = $response->getBody($this->request);

				if( $response instanceof MultiResponseInterface ) {
					$response->next();
					self::storeResponse($this->tmpPath, $response);
				}

				echo $body;

				return;
			}

			http_response_code(404);
			echo MockWebServer::VND . ": Resource '{$path}' not found!\n";

			return;
		}

		header('Content-Type: application/json');

		echo json_encode($this->request, JSON_PRETTY_PRINT);
	}

	/**
	 * @return false|string
	 */
	protected function getDataPath() {
		$path = false;

		$uriPath   = $this->request->getParsedUri()['path'];
		$uriPath = self::resolvePath($this->tmpPath, $uriPath);
		$aliasPath = self::aliasPath($this->tmpPath, $uriPath);
		if( file_exists($aliasPath) ) {
			if( $path = file_get_contents($aliasPath) ) {
				$path = $this->tmpPath . DIRECTORY_SEPARATOR . $path;
			}
		} elseif( preg_match('%^/' . preg_quote(MockWebServer::VND) . '/([0-9a-fA-F]{32})$%', $uriPath, $matches) ) {
			$path = $this->tmpPath . DIRECTORY_SEPARATOR . $matches[1];
		}

		return $path;
	}

	/**
	 * @internal
	 * @param string                                  $tmpPath
	 * @param \donatj\MockWebServer\ResponseInterface $response
	 * @return string
	 */
	public static function storeResponse( $tmpPath, ResponseInterface $response ) {
		$ref     = $response->getRef();
		$content = serialize($response);

		if( !file_put_contents($tmpPath . DIRECTORY_SEPARATOR . $ref, $content) ) {
			throw new Exceptions\RuntimeException('Failed to write temporary content');
		}

		return $ref;
	}

	/**
	 * @internal
	 * @param string $tmpPath
	 * @param string $path
	 */
	public static function storePath( $tmpPath, $path ) {
		$pathListFilepath = $tmpPath . DIRECTORY_SEPARATOR . self::PATH_LIST_FILE;
		$paths = [];
		$content = false;

		if (file_exists($pathListFilepath)) {
			$content = file_get_contents($pathListFilepath);
		}

		if ($content) {
			$paths = explode('\n', $content);
		}

		$paths[] = $path;
		$content = implode('\n', $paths);

		if( !file_put_contents( $pathListFilepath, $content) ) {
			throw new Exceptions\RuntimeException('Failed to write temporary path list');
		}
	}

	/**
	 * @internal
	 * @param string $tmpPath
	 * @param string $requestPath
	 * @return mixed|string|null
	 */
	public static function resolvePath( $tmpPath, $requestPath ) {
		$pathListFilepath = $tmpPath . DIRECTORY_SEPARATOR . self::PATH_LIST_FILE;
		$paths = [];

		if (file_exists($pathListFilepath)) {
			$content = file_get_contents($pathListFilepath);
			if ($content) {
				$paths = explode('\n', $content);
			}
		}

		foreach ($paths as $pattern) {
			if ($requestPath === $pattern) {
				return $pattern;
			}

			// shouldn't use preg_quote, just a hacky way
			$escapedPattern = str_replace('/', '\/', $pattern);
			if (preg_match('/' . $escapedPattern . '$/i', $requestPath, $matches)) {
				return $pattern;
			}
		}

		return null;
	}

}
