<?php

namespace App\JsonApi\V1;

use App\JsonApi\V1\VideoJobs\VideoJobSchema;
use LaravelJsonApi\Core\Server\Server as BaseServer;
use App\JsonApi\V1\ModelFiles\ModelFileSchema;
use App\JsonApi\V1\Categories\CategorySchema;
use App\JsonApi\V1\Tags\TagSchema;
use App\JsonApi\V1\Items\ItemSchema;
use App\JsonApi\V1\Roles\RoleSchema;
use App\JsonApi\V1\Generators\GeneratorSchema;
use App\JsonApi\V1\Permissions\PermissionSchema;

use App\JsonApi\V1\Users\UserSchema;

class Server extends BaseServer
{

    /**
     * The base URI namespace for this server.
     *
     * @var string
     */
    protected string $baseUri = '/api/v1';

    /**
     * Bootstrap the server when it is handling an HTTP request.
     *
     * @return void
     */
    public function serving(): void
    {
    }
 
    /**
     * Get the server's list of schemas.
     *
     * @return array
     */
    protected function allSchemas(): array
    {
        return [
            ModelFileSchema::class,
            VideoJobSchema::class,
            UserSchema::class,
            TagSchema::class,
            RoleSchema::class,
            CategorySchema::class,
            ItemSchema::class,
	    GeneratorSchema::class,
            PermissionSchema::class

        ];
    }
}
