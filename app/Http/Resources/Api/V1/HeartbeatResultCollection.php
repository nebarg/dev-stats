<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

class HeartbeatResultCollection extends ResourceCollection
{
    /**
     * @var class-string
     */
    public $collects = HeartbeatResultResource::class;

    /**
     * @var string
     */
    public static $wrap = 'responses';
}
