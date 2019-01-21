<?php

namespace Swoft\Server\Swoole;

use Co\Server;


/**
 * Interface PacketInterface
 *
 * @since 2.0
 */
interface PacketInterface
{
    /**
     * Packet event
     *
     * @param Server $server
     * @param string $data
     * @param array  $clientInfo
     */
    public function onPacket(Server $server, string $data, array $clientInfo): void;
}