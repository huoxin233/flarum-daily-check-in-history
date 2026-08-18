<?php

namespace Mattoid\CheckinHistory\Api\Serializer;

use Flarum\Api\Serializer\ForumSerializer;

class AddForumAttributes
{
    public function __invoke(ForumSerializer $serializer, array $attributes): array
    {
        $attributes['canQueryOthersHistory'] = $serializer->getActor()->can('checkin.queryOthersHistory');

        return $attributes;
    }
}
