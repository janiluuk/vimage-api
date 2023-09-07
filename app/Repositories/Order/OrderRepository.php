<?php

declare(strict_types=1);

namespace App\Repositories\Order;

use App\Models\Order;
use Illuminate\Support\Collection;
use App\Repositories\BaseRepository;

class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function save(Order $order): Order
    {
        $order->save();

        return $order;
    }

    public function update(Order $order): Order
    {
        $order->update();

        return $order;
    }

    public function getById(int $id): ?Order
    {
        return Order::firstWhere('id', $id);
    }

    public function getByUuid(int $id): ?Order
    {
        return Order::firstWhere('id', $id);
    }


    public function getByUserCustomerId(int $userCustomerId): ?Collection
    {
        return Order::where('user_id', '=', $userCustomerId)->get();
    }

    public function findByCriteria(array $criteria): Collection
    {
        $query = Order::query();

        foreach ($criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query->get();
    }
}

