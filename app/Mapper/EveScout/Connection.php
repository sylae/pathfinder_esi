<?php


namespace Exodus4D\ESI\Mapper\EveScout;

use Exodus4D\Pathfinder\Data\Mapper\AbstractIterator;

class Connection extends AbstractIterator {

    /**
     * @var array
     */
    protected static $map = [
        'id'                                => 'id',
        'type'                              => 'type',

        'status'                            => ['state' => 'name'],
        'statusUpdatedAt'                   => ['state' => 'updated'],

        'sourceSolarSystem'                 => 'source',
        'destinationSolarSystem'            => 'target',

        'signatureId'                       => ['sourceSignature' => 'name'],
        'sourceWormholeType'                => ['sourceSignature' => 'type'],

        'wormholeDestinationSignatureId'    => ['targetSignature' => 'name'],
        'destinationWormholeType'           => ['targetSignature' => 'type'],

        'wormholeMass'                      => ['wormhole' => 'mass'],
        'wormholeEol'                       => ['wormhole' => 'eol'],

        'createdAt'                         => ['created' => 'created'],
        'updatedAt'                         => ['updated' => 'updated']
    ];
}