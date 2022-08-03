<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            //'user_id' => new UserProfileResource($this->user_id),
            //"user"=> new UserProfileResource($this->whenLoaded('user')),
            
            'user_id' => $this->user_id,
            //'timeline_id' => new TimelineResource($this->timeline_id),
            'timeline_id' => $this->timeline_id,
            "first_name_ar"=> $this->first_name_ar,
            "middle_name_ar"=> $this->middle_name_ar,
            "last_name_ar"=> $this->last_name_ar,
            "country"=> $this->country,
            "resident "=> $this->resident,
            "phone"=> $this->phone,
            "occupation"=> $this->occupation,
            "birthdate"=> $this->birthdate,
            "bio"=> $this->bio,
            "cover_picture"=> $this->cover_picture,
            "fav_writer"=> $this->fav_writer,
            "fav_book"=> $this->fav_book,
            "fav_section"=> $this->fav_section,
            "fav_quote"=> $this->fav_quote,
            "extraspace"=> $this->extraspace,
        ];
    }
}
