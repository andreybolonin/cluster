<?php

namespace Amp\Cluster\Internal;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use Amp\Socket\ClientSocket;
use function Amp\call;

final class IpcClient {
    const TYPE_PING = 0;
    const TYPE_DATA = 1;
    const TYPE_IMPORT_SOCKET = 2;
    const TYPE_SELECT_PORT = 3;

    /** @var string|null */
    private $importWatcher;

    /** @var Channel */
    private $channel;

    /** @var callable */
    private $onData;

    /** @var \SplQueue */
    private $pendingResponses;

    public function __construct(Channel $channel, ClientSocket $socket, callable $onData) {
        $this->channel = $channel;
        $this->onData = $onData;
        $this->pendingResponses = $pendingResponses = new \SplQueue;

        $this->importWatcher = Loop::onReadable($socket->getResource(), static function ($watcher, $socket) use ($pendingResponses) {
            if ($pendingResponses->isEmpty()) {
                throw new \RuntimeException("Unexpected import-socket message.");
            }

            /** @var Deferred $pendingSocketImport */
            $pendingSocketImport = $pendingResponses->shift();

            $socket = \socket_import_stream($socket);
            $data = ["controllen" => \socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS) + 4]; // 4 == sizeof(int)

            \error_clear_last();
            if (!@\socket_recvmsg($socket, $data)) {
                $error = \error_get_last()["message"] ?? "Unknown error";
                $pendingSocketImport->fail(new \RuntimeException("Could not transfer socket: " . $error));
            } else {
                $socket = $data["control"][0]["data"][0];
                $pendingSocketImport->resolve($socket);
            }

            if ($pendingResponses->isEmpty()) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->importWatcher);
    }

    public function __destruct() {
        if ($this->importWatcher !== null) {
            Loop::cancel($this->importWatcher);
        }
    }

    public function run(): Promise {
        return call(function () {
            while (null !== $message = yield $this->channel->receive()) {
                yield from $this->handleMessage($message);
            }
        });
    }

    public function close(): Promise {
        return $this->channel->send(null);
    }

    private function handleMessage(array $message): \Generator {
        \assert(\count($message) >= 1);

        switch ($message[0]) {
            case self::TYPE_PING:
                yield $this->channel->send([self::TYPE_PING]);
                break;

            case self::TYPE_IMPORT_SOCKET:
                Loop::enable($this->importWatcher);
                break;

            case self::TYPE_SELECT_PORT:
                if ($this->pendingResponses->isEmpty()) {
                    throw new \RuntimeException("Unexpected select-port message.");
                }

                $deferred = $this->pendingResponses->shift();
                $deferred->resolve($message[1]);
                break;

            case self::TYPE_DATA:
                ($this->onData)($message[1], $message[2]);
                break;

            default:
                throw new \UnexpectedValueException("Unexpected message type");
        }
    }

    public function importSocket(string $uri): Promise {
        return call(function () use ($uri) {
            $deferred = new Deferred;
            $this->pendingResponses->push($deferred);

            yield $this->channel->send([self::TYPE_IMPORT_SOCKET, $uri]);

            return yield $deferred->promise();
        });
    }

    public function selectPort(string $uri): Promise {
        return call(function () use ($uri) {
            $deferred = new Deferred;
            $this->pendingResponses->push($deferred);

            yield $this->channel->send([self::TYPE_SELECT_PORT, $uri]);

            return yield $deferred->promise();
        });
    }

    public function send(string $event, $data): Promise {
        return $this->channel->send([self::TYPE_DATA, $event, $data]);
    }
}
