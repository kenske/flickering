<?php
namespace Flickering;

use Flickering\OAuth\Consumer;
use Flickering\OAuth\User;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use tmhOAuth;
use Underscore\Methods\ArraysMethods as Arrays;
use Underscore\Parse;

/**
 * Sends requests against the API
 */
class Request {
	/**
	 * The IoC Contaienr
	 *
	 * @var Container
	 */
	protected $app;

	/**
	 * The OAuth Consumer
	 *
	 * @var Consumer
	 */
	protected $consumer;

	/**
	 * The OAuth User
	 *
	 * @var User
	 */
	protected $user;

	/**
	 * Instance of the Cache class
	 *
	 * @var Cache
	 */
	protected $cache;

	/**
	 * Instance of the Config class
	 *
	 * @var Config
	 */
	protected $config;

	/**
	 * The POST parameters to request
	 *
	 * @var array
	 */
	protected $parameters;

	/**
	 * What to cache the request as
	 *
	 * @var string
	 */
	protected $hash;

	/**
	 * Create a new Request
	 *
	 * @param array $parameters
	 * @param Consumer $consumer
	 * @param User $user
	 * @param Container $app
	 */
	public function __construct(array $parameters, Consumer $consumer, User $user, Container $app) {
		$this->app = $app;

		// OAuth
		$this->consumer = $consumer;
		$this->user = $user;

		// Request parameters
		$this->parameters = $parameters;
		$this->hash = $this->createHashFromParameters($parameters);
	}

	////////////////////////////////////////////////////////////////////
	////////////////////////////// RESULTS /////////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get the Request cache hash
	 *
	 * @return string
	 */
	public function getHash() {
		return $this->hash;
	}

	/**
	 * Get the parsed response from the Request
	 *
	 * @return array
	 */
	public function getResponse() {
		return $this->execute();
	}

	/**
	 * Get the raw response from the Request
	 *
	 * @return string
	 */
	public function getRawResponse() {
		return $this->execute(false);
	}

	/**
	 * Get the results of the Request as a Results instance
	 *
	 * @param string|null $subresults A subresult array to fetch from results
	 *
	 * @return Results
	 */
	public function getResults($subresults = null) {
		$results = $this->execute();
		$results = new Collection($results);

		// Fetch results from sub-arrays
		$results = $results->first();
		if ($subresults) {
			$results = $results->get($subresults);
		}

		return $results;
	}

	////////////////////////////////////////////////////////////////////
	/////////////////////////// CORE METHODS ///////////////////////////
	////////////////////////////////////////////////////////////////////

	/**
	 * Get the lifetime of the cache
	 *
	 * @return integer|null
	 */
	public function getCacheLifetime() {
		// If cache disabled, always return false
		if (!$this->app['config']->get('flickering::config.cache.cache_requests')) {
			return null;
		}

		return (int)$this->app['config']->get('flickering::config.cache.lifetime');
	}

	/**
	 * Get the caching hash of the Method
	 *
	 * @param array $parameters The parameters to hash
	 *
	 * @return string
	 */
	protected function createHashFromParameters($parameters) {
		$parameters = $this->sortKeys($parameters);
		$parameters = $this->clean($parameters);

		$hash = array();
		foreach ($parameters as $k => $v) {
			$hash[] = $k . '-' . $v;
		}

		if ($this->user) {
			$hash[] = $this->user->getUid();
		}

		return implode('-', $hash);
	}

	/**
	 * Clean all falsy values from an array.
	 */
	protected function clean($array) {
		return array_filter($array, function ($value) {
			return (bool)$value;
		});
	}

	/**
	 * Sort an array by key.
	 */
	protected function sortKeys($array, $direction = 'ASC') {
		$direction = (strtolower($direction) === 'desc') ? SORT_DESC : SORT_ASC;
		if ($direction === SORT_ASC) {
			ksort($array);
		} else {
			krsort($array);
		}
		return $array;
	}


	/**
	 * Execute the Request against the API
	 *
	 * @param boolean $parse
	 *
	 * @return array
	 */
	protected function execute($parse = true) {
		$user = $this->user;
		$consumer = $this->consumer;
		$parameters = $this->parameters;

		return $this->app['cache']->remember($parse . $this->hash, $this->getCacheLifetime(), function () use (
			$parse,
			$user,
			$consumer,
			$parameters
		) {

			// Create OAuth request
			$request = new TmhOAuth(array(
				'consumer_key' => $consumer->getKey(),
				'consumer_secret' => $consumer->getSecret(),
				'host' => Flickering::API_URL,
				'use_ssl' => true,
				'user_token' => $user->getKey(),
				'user_secret' => $user->getSecret(),
			));
			$request->request('GET', $request->url(''), $parameters);
			$response = $request->response['response'];

			// Return raw if requested
			if (!$parse) {
				return $response;
			}

			return json_decode($response, true);
		});
	}
}
