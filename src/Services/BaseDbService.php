<?php

namespace DreamFactory\Core\Services;

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseDbResource;

abstract class BaseDbService extends BaseRestService
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var array Array of resource defining arrays
     */
    protected $resources = [];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $output = parent::getAccessList();
        $refresh = $this->request->getParameterAsBool(ApiOptions::REFRESH);
        $schema = $this->request->getParameter(ApiOptions::SCHEMA, '');

        foreach ($this->resources as $resourceInfo) {
            $className = $resourceInfo['class_name'];

            if (!class_exists($className)) {
                throw new InternalServerErrorException('Service configuration class name lookup failed for resource ' .
                    $this->resourcePath);
            }

            /** @var BaseDbResource $resource */
            $resource = $this->instantiateResource($className, $resourceInfo);
            $access = $this->getPermissions($resource->name);
            if (!empty($access)) {
                $output[] = $resource->name . '/';
                $output[] = $resource->name . '/*';

                $results = $resource->listAccessComponents($schema, $refresh);
                $output = array_merge($output, $results);
            }
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        return ($only_handlers) ? $this->resources : array_values($this->resources);
    }
}