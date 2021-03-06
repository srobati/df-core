<?php

use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Core\Enums\ApiOptions;
use Illuminate\Support\Arr;

class SystemServiceTest extends \DreamFactory\Core\Testing\TestCase
{
    const RESOURCE = 'service';

    protected $serviceId = 'system';

    /************************************************
     * Testing GET
     ************************************************/

    public function testGETService()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $content = $rs->getContent();
        $services = Arr::get($content, static::$wrapper);

        $first2 = Arr::get($services, '0.name');
        $first2 .= ',' . Arr::get($services, '1.name');

        $this->assertEquals('system,api_docs', $first2);
    }

    public function testGETServiceById()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/1');
        $content = $rs->getContent();

        $this->assertEquals('system', Arr::get($content, 'name'));
        $this->assertEquals(13, count($content));
    }

    public function testGETServiceByIdWithFields()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/1', [ApiOptions::FIELDS => 'name,label,id']);
        $content = $rs->getContent();

        $this->assertEquals('system', Arr::get($content, 'name'));
        $this->assertEquals('System Management', Arr::get($content, 'label'));
        $this->assertEquals(1, Arr::get($content, 'id'));
        $this->assertEquals(3, count($content));
    }
}