<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;
use LaravelJsonApi\Laravel\Http\Controllers\Actions;
use App\Models\Videojob;
use Illuminate\Support\Facades\Auth;
use LaravelJsonApi\Laravel\Http\Requests\ResourceQuery;
use LaravelJsonApi\Contracts\Routing\Route;
use LaravelJsonApi\Core\Responses\DataResponse;
use LaravelJsonApi\Contracts\Store\Store as StoreContract;

class VideojobApiController extends Controller
{
    use Actions\FetchMany;
    use Actions\FetchOne;
    use Actions\Store;
    use Actions\Update;
    use Actions\Destroy;
    use Actions\FetchRelated;
    use Actions\FetchRelationship;
    use Actions\UpdateRelationship;
    use Actions\AttachRelationship;
    use Actions\DetachRelationship;
  
}
