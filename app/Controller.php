<?php

namespace TelegramRSS;

class Controller
{
	/** @var array */
	private $request = [
		'ip' => '',
		'peer' => '',
		'limit' => 10,
		'images' => true,
	];

	private $responseList = [
		'html'=> [
			'type'=> 'html',
			'headers' => [
				['Content-Type', 'text/html;charset=utf-8'],
			],
		],
		'rss'=>[
			'type'=> 'rss',
			'headers' => [
				['Content-Type', 'application/rss+xml;charset=utf-8'],
			],
		],
		'json'=>[
			'type'=> 'json',
			'headers' => [
				['Content-Type', 'application/json;charset=utf-8'],
			],
		]
	];

	/** @var array */
	private $response = [
		'errors' => [],
		'type' => '',
		'headers'=>[],
		'code' => 200,
		'data' => null,
	];

	private $indexPage = __DIR__ . '/../index.html';

    public function __construct(\Swoole\Http\Request $request, \Swoole\Http\Response $response, Client $client)
    {

        //Parse request and generate response

	    $this
		    ->parseRequest($request)
		    ->validate()
		    ->generateResponse($client)
		    ->checkErrors()
	        ->encodeResponse()
	    ;


	    foreach ($this->response['headers'] as $header) {
		    $response->header(...$header);
	    }

        $response->status($this->response['code']);
        $response->end($this->response['data']);
    }

	/**
	 * @param \Swoole\Http\Request $request
	 * @return Controller
	 */
    private function parseRequest(\Swoole\Http\Request $request):self {

    	$this->request['ip'] = $request->server['remote_addr'];

	    $path = array_values(array_filter(explode('/',  $request->server['request_uri'])));

	    if (!is_array($path) || count($path) !== 2) {
		    $this->response['type'] = 'html';
		    return $this;
	    }

	    if (array_key_exists($path[0], $this->responseList)) {
	    	$this->response['type'] = $this->responseList[$path[0]]['type'];
		    $this->request['peer'] = urldecode($path[1]);
	    } else {
		    $this->response['errors'][] = 'Unknown response format';
	    }

	    return $this;
    }

    private function validate(){

	    if (preg_match('/[^\w\-]/', $this->request['peer'])){
		    $this->response['errors'][] = "WRONG NAME";
	    }

	    if (preg_match('/bot$/i', $this->request['peer'])){
		    $this->response['errors'][] = "BOTS NOT ALLOWED";
	    }

	    if (preg_match('/[A-Z]/', $this->request['peer'])) {
		    $this->response['errors'][] = "UPPERCASE NOT SUPPORTED";
	    }

	    //TODO: search ip in blacklist.
    	return $this;
    }

	/**
	 * @param Client $client
	 * @return Controller
	 */
	private function generateResponse(Client $client):self {

		if ($this->response['errors']) {
			return $this;
		}

		try {
			if ($this->request['peer']) {
				$this->response['data'] = $client->getHistory(['peer' => $this->request['peer']]);
				if ($this->response['data']->_ !== 'messages.channelMessages') {
					throw new \UnexpectedValueException('This is not a channel');
				}
			}
		} catch (\Exception $e) {
			$this->response['errors'][] = [
				'code' => $e->getCode(),
				'message' => $e->getMessage(),
			];
		}

		return $this;
	}

	private function checkErrors():self {

		if (!$this->response['errors']) {
			return $this;
		}

		$this->response['type'] = 'json';
		$this->response['code'] = 400;
		$this->response['data'] = [
			'errors' => $this->response['errors'],
		];

		return $this;
	}

	/**
	 * Кодирует ответ в нужный формат: json
	 *
	 * @return Controller
	 */
	public function encodeResponse($firstRun = true): self
	{
		try{
			switch ($this->response['type']) {
				case 'html':
					$result = file_get_contents($this->indexPage);
					break;
				case 'json':
					$result = json_encode(
						$this->response['data'],
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					);
					break;
				case 'rss':
					$messages = new Messages($this->response['data']);
					$rss = new RSS($messages->get());
					$result = $rss->get();
					break;
				default:
					$result = 'Unknown response type';
			}

			$this->response['data'] = $result;
			$this->response['headers'] = $this->responseList[$this->response['type']]['headers'];
		} catch (\Exception $e){
			$this->response['errors'][] = [
				'code' => $e->getCode(),
				'message' => $e->getMessage(),
			];
			if ($firstRun){
				$this->checkErrors()->encodeResponse(false);
			}
		}


		return $this;
	}
}