<?php

namespace Mattoid\CheckinHistory\Tests\Unit;

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Testing\unit\TestCase;
use Flarum\User\User;
use Mattoid\CheckinHistory\Api\Serializer\AddForumAttributes;
use Mockery as m;

class AddForumAttributesTest extends TestCase
{
    /**
     * @test
     */
    public function adds_can_query_others_history_when_allowed()
    {
        $actor = m::mock(User::class);
        $actor->shouldReceive('can')->with('checkin.queryOthersHistory')->andReturn(true);

        $serializer = m::mock(ForumSerializer::class);
        $serializer->shouldReceive('getActor')->andReturn($actor);

        $adder = new AddForumAttributes();
        $attributes = $adder($serializer, []);

        $this->assertArrayHasKey('canQueryOthersHistory', $attributes);
        $this->assertTrue($attributes['canQueryOthersHistory']);
    }

    /**
     * @test
     */
    public function adds_can_query_others_history_when_disallowed()
    {
        $actor = m::mock(User::class);
        $actor->shouldReceive('can')->with('checkin.queryOthersHistory')->andReturn(false);

        $serializer = m::mock(ForumSerializer::class);
        $serializer->shouldReceive('getActor')->andReturn($actor);

        $adder = new AddForumAttributes();
        $attributes = $adder($serializer, []);

        $this->assertArrayHasKey('canQueryOthersHistory', $attributes);
        $this->assertFalse($attributes['canQueryOthersHistory']);
    }
}
