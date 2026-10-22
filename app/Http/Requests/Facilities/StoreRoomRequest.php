<?php

namespace App\Http\Requests\Facilities;

use App\Models\Room;
use Illuminate\Support\Facades\Gate;

class StoreRoomRequest extends RoomInformationRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Room::class);
    }
}
