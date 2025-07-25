<?php

namespace Illuminate\Http\Client;

use ArrayAccess;
use GuzzleHttp\Psr7\StreamWrapper;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Illuminate\Support\Traits\Macroable;
use LogicException;
use Stringable;

/**
 * @mixin \Psr\Http\Message\ResponseInterface
 */
class Response implements ArrayAccess, Stringable
{
    use Concerns\DeterminesStatusCode, Macroable {
        __call as macroCall;
    }

    /**
     * The underlying PSR response.
     *
     * @var \Psr\Http\Message\ResponseInterface
     */
    protected $response;

    /**
     * The decoded JSON response.
     *
     * @var array
     */
    protected $decoded;

    /**
     * The request cookies.
     *
     * @var \GuzzleHttp\Cookie\CookieJar
     */
    public $cookies;

    /**
     * The transfer stats for the request.
     *
     * @var \GuzzleHttp\TransferStats|null
     */
    public $transferStats;

    /**
     * The length at which request exceptions will be truncated.
     *
     * @var int<1, max>|false|null
     */
    protected $truncateExceptionsAt = null;

    /**
     * Create a new response instance.
     *
     * @param  \Psr\Http\Message\MessageInterface  $response
     */
    public function __construct($response)
    {
        $this->response = $response;
    }

    /**
     * Get the body of the response.
     *
     * @return string
     */
    public function body()
    {
        return (string) $this->response->getBody();
    }

    /**
     * Get the JSON decoded body of the response as an array or scalar value.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function json($key = null, $default = null)
    {
        if (! $this->decoded) {
            $this->decoded = json_decode($this->body(), true);
        }

        if (is_null($key)) {
            return $this->decoded;
        }

        return data_get($this->decoded, $key, $default);
    }

    /**
     * Get the JSON decoded body of the response as an object.
     *
     * @return object|null
     */
    public function object()
    {
        return json_decode($this->body(), false);
    }

    /**
     * Get the JSON decoded body of the response as a collection.
     *
     * @param  string|null  $key
     * @return \Illuminate\Support\Collection
     */
    public function collect($key = null)
    {
        return new Collection($this->json($key));
    }

    /**
     * Get the JSON decoded body of the response as a fluent object.
     *
     * @param  string|null  $key
     * @return \Illuminate\Support\Fluent
     */
    public function fluent($key = null)
    {
        return new Fluent((array) $this->json($key));
    }

    /**
     * Get the body of the response as a PHP resource.
     *
     * @return resource
     *
     * @throws \InvalidArgumentException
     */
    public function resource()
    {
        return StreamWrapper::getResource($this->response->getBody());
    }

    /**
     * Get a header from the response.
     *
     * @param  string  $header
     * @return string
     */
    public function header(string $header)
    {
        return $this->response->getHeaderLine($header);
    }

    /**
     * Get the headers from the response.
     *
     * @return array
     */
    public function headers()
    {
        return $this->response->getHeaders();
    }

    /**
     * Get the status code of the response.
     *
     * @return int
     */
    public function status()
    {
        return (int) $this->response->getStatusCode();
    }

    /**
     * Get the reason phrase of the response.
     *
     * @return string
     */
    public function reason()
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Get the effective URI of the response.
     *
     * @return \Psr\Http\Message\UriInterface|null
     */
    public function effectiveUri()
    {
        return $this->transferStats?->getEffectiveUri();
    }

    /**
     * Determine if the request was successful.
     *
     * @return bool
     */
    public function successful()
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * Determine if the response was a redirect.
     *
     * @return bool
     */
    public function redirect()
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    /**
     * Determine if the response indicates a client or server error occurred.
     *
     * @return bool
     */
    public function failed()
    {
        return $this->serverError() || $this->clientError();
    }

    /**
     * Determine if the response indicates a client error occurred.
     *
     * @return bool
     */
    public function clientError()
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /**
     * Determine if the response indicates a server error occurred.
     *
     * @return bool
     */
    public function serverError()
    {
        return $this->status() >= 500;
    }

    /**
     * Execute the given callback if there was a server or client error.
     *
     * @param  callable|(\Closure(\Illuminate\Http\Client\Response): mixed)  $callback
     * @return $this
     */
    public function onError(callable $callback)
    {
        if ($this->failed()) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Get the response cookies.
     *
     * @return \GuzzleHttp\Cookie\CookieJar
     */
    public function cookies()
    {
        return $this->cookies;
    }

    /**
     * Get the handler stats of the response.
     *
     * @return array
     */
    public function handlerStats()
    {
        return $this->transferStats?->getHandlerStats() ?? [];
    }

    /**
     * Close the stream and any underlying resources.
     *
     * @return $this
     */
    public function close()
    {
        $this->response->getBody()->close();

        return $this;
    }

    /**
     * Get the underlying PSR response for the response.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function toPsrResponse()
    {
        return $this->response;
    }

    /**
     * Create an exception if a server or client error occurred.
     *
     * @return \Illuminate\Http\Client\RequestException|null
     */
    public function toException()
    {
        if ($this->failed()) {
            $originalTruncateAt = RequestException::$truncateAt;

            try {
                if ($this->truncateExceptionsAt !== null) {
                    $this->truncateExceptionsAt === false
                        ? RequestException::dontTruncate()
                        : RequestException::truncateAt($this->truncateExceptionsAt);
                }

                return new RequestException($this);
            } finally {
                RequestException::$truncateAt = $originalTruncateAt;
            }
        }
    }

    /**
     * Throw an exception if a server or client error occurred.
     *
     * @param  null|(\Closure(\Illuminate\Http\Client\Response, \Illuminate\Http\Client\RequestException): mixed)  $callback
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throw($callback = null)
    {
        if ($this->failed()) {
            throw tap($this->toException(), function ($exception) use ($callback) {
                if ($callback && is_callable($callback)) {
                    $callback($this, $exception);
                }
            });
        }

        return $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to true.
     *
     * @param  \Closure|bool  $condition
     * @param  null|(\Closure(\Illuminate\Http\Client\Response, \Illuminate\Http\Client\RequestException): mixed)  $callback
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throwIf($condition, $callback = null)
    {
        return value($condition, $this) ? $this->throw($callback) : $this;
    }

    /**
     * Throw an exception if the response status code matches the given code.
     *
     * @param  int|(\Closure(int, \Illuminate\Http\Client\Response): bool)|callable  $statusCode
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throwIfStatus($statusCode)
    {
        if (is_callable($statusCode) &&
            $statusCode($this->status(), $this)) {
            return $this->throw();
        }

        return $this->status() === $statusCode ? $this->throw() : $this;
    }

    /**
     * Throw an exception unless the response status code matches the given code.
     *
     * @param  int|(\Closure(int, \Illuminate\Http\Client\Response): bool)|callable  $statusCode
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throwUnlessStatus($statusCode)
    {
        if (is_callable($statusCode)) {
            return $statusCode($this->status(), $this) ? $this : $this->throw();
        }

        return $this->status() === $statusCode ? $this : $this->throw();
    }

    /**
     * Throw an exception if the response status code is a 4xx level code.
     *
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throwIfClientError()
    {
        return $this->clientError() ? $this->throw() : $this;
    }

    /**
     * Throw an exception if the response status code is a 5xx level code.
     *
     * @return $this
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function throwIfServerError()
    {
        return $this->serverError() ? $this->throw() : $this;
    }

    /**
     * Indicate that request exceptions should be truncated to the given length.
     *
     * @param  int<1, max>  $length
     * @return $this
     */
    public function truncateExceptionsAt(int $length)
    {
        $this->truncateExceptionsAt = $length;

        return $this;
    }

    /**
     * Indicate that request exceptions should not be truncated.
     *
     * @return $this
     */
    public function dontTruncateExceptions()
    {
        $this->truncateExceptionsAt = false;

        return $this;
    }

    /**
     * Dump the content from the response.
     *
     * @param  string|null  $key
     * @return $this
     */
    public function dump($key = null)
    {
        $content = $this->body();

        $json = json_decode($content);

        if (json_last_error() === JSON_ERROR_NONE) {
            $content = $json;
        }

        if (! is_null($key)) {
            dump(data_get($content, $key));
        } else {
            dump($content);
        }

        return $this;
    }

    /**
     * Dump the content from the response and end the script.
     *
     * @param  string|null  $key
     * @return never
     */
    public function dd($key = null)
    {
        $this->dump($key);

        exit(1);
    }

    /**
     * Dump the headers from the response.
     *
     * @return $this
     */
    public function dumpHeaders()
    {
        dump($this->headers());

        return $this;
    }

    /**
     * Dump the headers from the response and end the script.
     *
     * @return never
     */
    public function ddHeaders()
    {
        $this->dumpHeaders();

        exit(1);
    }

    /**
     * Determine if the given offset exists.
     *
     * @param  string  $offset
     * @return bool
     */
    public function offsetExists($offset): bool
    {
        return isset($this->json()[$offset]);
    }

    /**
     * Get the value for a given offset.
     *
     * @param  string  $offset
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->json()[$offset];
    }

    /**
     * Set the value at the given offset.
     *
     * @param  string  $offset
     * @param  mixed  $value
     * @return void
     *
     * @throws \LogicException
     */
    public function offsetSet($offset, $value): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Unset the value at the given offset.
     *
     * @param  string  $offset
     * @return void
     *
     * @throws \LogicException
     */
    public function offsetUnset($offset): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Get the body of the response.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->body();
    }

    /**
     * Dynamically proxy other methods to the underlying response.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return static::hasMacro($method)
            ? $this->macroCall($method, $parameters)
            : $this->response->{$method}(...$parameters);
    }
}
