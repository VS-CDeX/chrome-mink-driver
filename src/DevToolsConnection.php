<?php

namespace DMore\ChromeDriver;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;

class DevToolsConnection implements EventEmitterInterface
{
    use EventEmitterTrait;

    /** @var int */
    private $command_id = 1;
    /** @var string */
    private $url;
    /** @var bool */
    private $connected = false;
    /** @var WebSocket */
    private $socket;
    protected $response_queue = [];
    /** @var LoopInterface */
    private $loop;

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function connect($url = null)
    {
        $url = $url == null ? $this->url : $url;
        $this->loop = Factory::create();

        $connector = new Connector($this->loop);
        $connect = $connector($url, [], []);
        $connect->then(function (WebSocket $socket) {
            $this->connected = true;
            $this->socket = $socket;

            $socket->on('message', function (MessageInterface $message) {
                $this->receive($message);
            });

            $socket->on('close', function () {
                $this->connected = false;
            });
        });
    }

    public function close()
    {
        $this->socket->close();
    }

    /**
     * @param string $command
     * @param array $parameters
     * @return array
     * @throws \Exception
     */
    public function send($command, array $parameters = [])
    {
        $command_id = $this->asyncSend($command, $parameters);
        $response = [];
        $listener = function ($data) use (&$response, $command_id) {
            if ($data['id'] == $command_id) {
                $response = $data;
            }
        };

        $this->on('response', $listener);

        while ([] === $response) {
            $this->tick();
        }

        $this->removeListener('response', $listener);

        return $response['result'];
    }

    public function asyncSend($command, array $parameters = []) : int
    {
        while (!$this->connected) {
            $this->tick();
        }

        $payload['id'] = $this->command_id++;
        $payload['method'] = $command;

        if (!empty($parameters)) {
            $payload['params'] = $parameters;
        }

        $this->socket->send(json_encode($payload));

        return $payload['id'];
    }

    public function waitFor(callable $is_ready)
    {
        do {
            $this->loop->tick();
        } while (!$is_ready());
    }

    public function tick()
    {
        $this->loop->tick();
    }

    protected function receive(MessageInterface $message)
    {
        $data = json_decode($message->getPayload(), true);

        if (array_key_exists('id', $data)) {
            $this->emit('response', [$data]);
        } elseif (array_key_exists('method', $data)) {
            $this->emit('event', [$data]);
        } else {
            throw new \Exception("Can't handle '{$message->getPayload()}'");
        }
    }
}
