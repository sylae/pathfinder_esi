<?php
/**
 * Created by PhpStorm.
 * User: Exodus 4D
 * Date: 05.01.2019
 * Time: 13:32
 */

namespace Exodus4D\ESI\Lib\Middleware;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class GuzzleLogMiddleware {

    /**
     * default for: global enable this middleware
     */
    const DEFAULT_LOG_ENABLED       = true;

    /**
     * default for: log message format
     */
    const DEFAULT_LOG_FORMAT        = '{method} {target} HTTP/{version} → {code} {phrase} {res_header_content-length}';

    /**
     * default for: log errors, e.g. ConnectException or other errors where no response available
     */
    const DEFAULT_LOG_ERROR         = true;

    /**
     * default for: log statistics (if available)
     */
    const DEFAULT_LOG_STATS         = false;

    /**
     * default for: log requests with HTTP 5xx response
     */
    const DEFAULT_LOG_5XX           = true;

    /**
     * default for: log requests with HTTP 4xx response
     */
    const DEFAULT_LOG_4XX           = true;

    /**
     * default for: log requests with HTTP 3xx response
     */
    const DEFAULT_LOG_3XX           = false;

    /**
     * default for: log requests with HTTP 2xx response
     */
    const DEFAULT_LOG_2XX           = false;

    /**
     * default for: log requests with HTTP 1xx response
     */
    const DEFAULT_LOG_1XX           = false;

    /**
     * default for: log all requests regardless of the HTTP status response code
     */
    const DEFAULT_LOG_ALL_STATUS    = false;

    /**
     * default for: log requests with specific HTTP status response
     */
    const DEFAULT_LOG_ON_STATUS     = [];

    /**
     * default for: exclude requests with specific HTTP status response.
     * This overwrites all other configured status options set before
     */
    const DEFAULT_LOG_OFF_STATUS    = [];

    /**
     * default for: callback function for logging
     */
    const DEFAULT_LOG_CALLBACK      = null;

    /**
     * default for: name for log file
     */
    const DEFAULT_LOG_FILE          = 'requests';

    /**
     * default options can go here for middleware
     * @var array
     */
    private $defaultOptions = [
        'log_enabled'               => self::DEFAULT_LOG_ENABLED,
        'log_format'                => self::DEFAULT_LOG_FORMAT,
        'log_error'                 => self::DEFAULT_LOG_ERROR,
        'log_stats'                 => self::DEFAULT_LOG_STATS,
        'log_5xx'                   => self::DEFAULT_LOG_5XX,
        'log_4xx'                   => self::DEFAULT_LOG_4XX,
        'log_3xx'                   => self::DEFAULT_LOG_3XX,
        'log_2xx'                   => self::DEFAULT_LOG_2XX,
        'log_1xx'                   => self::DEFAULT_LOG_1XX,
        'log_all_status'            => self::DEFAULT_LOG_ALL_STATUS,
        'log_on_status'             => self::DEFAULT_LOG_ON_STATUS,
        'log_off_status'            => self::DEFAULT_LOG_OFF_STATUS,
        'log_callback'              => self::DEFAULT_LOG_CALLBACK,
        'log_file'                  => self::DEFAULT_LOG_FILE
    ];

    /**
     * @var callable
     */
    private $nextHandler;

    /**
     * @var TransferStats|null
     */
    private $stats = null;

    /**
     * GuzzleLogMiddleware constructor.
     * @param callable $nextHandler
     * @param array $defaultOptions
     */
    public function __construct(callable $nextHandler, array $defaultOptions = []){
        $this->nextHandler = $nextHandler;
        $this->defaultOptions = array_replace($this->defaultOptions, $defaultOptions);
    }

    /**
     * log errors for requested URL
     * @param RequestInterface $request
     * @param array $options
     * @return mixed
     */
    public function __invoke(RequestInterface $request, array $options){
        // Combine options with defaults specified by this middleware
        $options = array_replace($this->defaultOptions, $options);

        $next = $this->nextHandler;

        // reset TransferStats
        $this->stats = null;

        // TransferStats can only be accessed through a callback -> 'on_stats' Core Guzzle option
        if($options['log_enabled'] && $options['log_stats'] && !isset($options['on_stats'])){
            $options['on_stats'] = function(TransferStats $stats){
                $this->stats = $stats;
            };
        }

        return $next($request, $options)->then(
            $this->onFulfilled($request, $options),
            $this->onRejected($request, $options)
        );
    }

    /**
     * No exceptions were thrown during processing
     * @param RequestInterface $request
     * @param array $options
     * @return \Closure
     */
    protected function onFulfilled(RequestInterface $request, array $options) : \Closure {
        return function (ResponseInterface $response) use ($request, $options) {
            var_dump('onFullFilled() Log');

            if($options['log_enabled']){
                $this->log($options, $request, $response);
            }

            return $response;
        };
    }

    /**
     * An exception or error was thrown during processing
     * @param RequestInterface $request
     * @param array $options
     * @return \Closure
     */
    protected function onRejected(RequestInterface $request, array $options) : \Closure {
        return function ($reason) use ($request, $options) {
            var_dump('onRejected() Log');
            var_dump(get_class($reason));
            if($options['log_enabled']){
                $response = null;
                if(($reason instanceof RequestException) && $reason->hasResponse()){
                        $response = $reason->getResponse();
                }

                $this->log($options, $request, $response, $reason);
            }

            return \GuzzleHttp\Promise\rejection_for($reason);
        };
    }

    /**
     * log request and response data based on $option flags
     * @param array $options
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param \Exception|null $exception
     */
    protected function log(array $options, RequestInterface $request, ?ResponseInterface $response, ?\Exception $exception = null) : void {
        $action = $options['log_file'];
        $level = 'info';
        $tag = 'information';
        $logData = [];
        $logRequestData = false;

        if(is_null($response)){
            // no response -> ConnectException or RequestException
            if($options['log_error']){
                if(!empty($reasonData = $this->logReason($exception))){
                    $logData['reason'] = $reasonData;
                    $logRequestData = true;
                    $level = 'critical';
                    $tag = 'danger';
                }
            }
        }else{
            $statusCode = $response->getStatusCode();
            if($this->checkStatusCode($options, $statusCode)){
                $logData['response'] = $this->logResponse($response);
                $logRequestData = true;

                if($this->is2xx($statusCode)){
                    $level = 'info';
                    $tag = 'success';
                }elseif($this->is4xx($statusCode)){
                    $level = 'error';
                    $tag = 'warning';
                }elseif($this->is5xx($statusCode)){
                    $level = 'critical';
                    $tag = 'warning';
                }
            }
        }

        if($logRequestData){
            $logData['request'] = $this->logRequest($request);
        }

        // log stats in case other logData should be logged
        if(!is_null($this->stats) && !empty($logData)){
            $logData['stats'] = $this->logStats($this->stats);
        }

        if(!empty($logData) && is_callable($log = $options['log_callback'])){
            $log($action, $level, $this->getLogMessage($options['log_format'], $logData), $logData, $tag);
        }
    }

    /**
     * log request
     * @param RequestInterface $request
     * @return array
     */
    protected function logRequest(RequestInterface $request) : array {
        return [
            'method'                    => $request->getMethod(),
            'url'                       => $request->getUri()->__toString(),
            'host'                      => $request->getUri()->getHost(),
            'path'                      => $request->getUri()->getPath(),
            'target'                    => $request->getRequestTarget(),
            'version'                   => $request->getProtocolVersion()
        ];
    }

    /**
     * log response -> this might be a HTTP 1xx up to 5xx response
     * @param ResponseInterface $response
     * @return array
     */
    protected function logResponse(ResponseInterface $response) : array {
        return [
            'code'                      => $response->getStatusCode(),
            'phrase'                    => $response->getReasonPhrase(),
            'version'                   => $response->getProtocolVersion(),
            'res_header_content-length' => $response->getHeaderLine('content-length')
        ];
    }

    /**
     * log reason -> rejected promise
     * ConnectException or parent of type RequestException
     * @param \Exception|null $exception
     * @return array
     */
    protected function logReason(?\Exception $exception) : array {
        $data = [];
        if($exception instanceof RequestException){
            $handlerContext = $exception->getHandlerContext();
            $data['errno'] = $handlerContext['errno'];
            $data['error'] = $handlerContext['error'];
        }
        return $data;
    }

    /**
     * log transfer stats
     * For debugging purpose
     * @param TransferStats $stats
     * @return array
     */
    protected function logStats(TransferStats $stats) : array {
        return [
            'time'                      => (string)$stats->getTransferTime()
        ];
    }

    /**
     * check response HTTP Status code for logging
     * @param array $options
     * @param int $statusCode
     * @return bool
     */
    protected function checkStatusCode(array $options, int $statusCode) : bool {
        if($options['log_all_status']){
            return true;
        }
        if(in_array($statusCode, (array)$options['log_off_status'])){
            return false;
        }
        if(in_array($statusCode, (array)$options['log_on_status'])){
            return true;
        }
        $statusLevel = (int)substr($statusCode, 0, 1);
        return (bool)$options['log_' . $statusLevel . 'xx'];
    }

    /**
     * check HTTP Status for 2xx response
     * @param int $statusCode
     * @return bool
     */
    protected function is2xx(int $statusCode) : bool {
        return (int)substr($statusCode, 0, 1) === 2;
    }

    /**
     * check HTTP Status for 4xx response
     * @param int $statusCode
     * @return bool
     */
    protected function is4xx(int $statusCode) : bool {
        return (int)substr($statusCode, 0, 1) === 4;
    }

    /**
     * check HTTP Status for 5xx response
     * @param int $statusCode
     * @return bool
     */
    protected function is5xx(int $statusCode) : bool {
        return (int)substr($statusCode, 0, 1) === 5;
    }

    /**
     * get formatted log message from $logData
     * @param string $message
     * @param array $logData
     * @return string
     */
    protected function getLogMessage(string $message, array $logData = []) : string {
        $replace = [
            '{method}'                      => $logData['request']['method'],
            '{url}'                         => $logData['request']['url'],
            '{host}'                        => $logData['request']['host'],
            '{path}'                        => $logData['request']['path'],
            '{target}'                      => $logData['request']['target'],
            '{version}'                     => $logData['request']['version'],

            '{code}'                        => $logData['response']['code'] ? : 'NULL',
            '{phrase}'                      => $logData['response']['phrase'] ? : '',
            '{res_header_content-length}'   => $logData['response']['res_header_content-length'] ? : 0
        ];

        return str_replace(array_keys($replace), array_values($replace), $message);
    }

    /**
     * @param array $defaultOptions
     * @return \Closure
     */
    public static function factory(array $defaultOptions = []) : \Closure {
        return function(callable $handler) use ($defaultOptions){
            return new static($handler, $defaultOptions);
        };
    }
}