<?php

namespace AyeCode\FlarumRestrictions;

use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Api\Serializer\PostSerializer;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Discussion\Discussion;
use AyeCode\FlarumRestrictions\Access\LicenseChecker;

return [
    // Content restriction
    (new Extend\ApiSerializer(PostSerializer::class))
        ->attributes(function(PostSerializer $serializer, Post $post, array $attributes) {
            if ($post->number === 1) {
                return $attributes;
            }

            if ($post->discussion && $post->discussion->tags) {
                foreach ($post->discussion->tags as $tag) {
                    if ($tag->slug === 'location-manager') {
                        $checker = new LicenseChecker();
                        if (!$checker->can_access($serializer->getActor())) {
                            $attributes['contentHtml'] = '<div class="Alert"><p>This content is restricted. Please purchase a Location Manager license to view replies.</p><p><a href="https://wpgeodirectory.com/downloads/location-manager/" class="Button">Purchase License</a></p></div>';
                            $attributes['content'] = 'This content is restricted. Please purchase a Location Manager license to view replies.';
                        }
                    }
                }
            }

            return $attributes;
        }),

    // Discussion permissions
    (new Extend\ApiSerializer(DiscussionSerializer::class))
        ->attributes(function(DiscussionSerializer $serializer, Discussion $discussion, array $attributes) {
            if ($discussion->tags) {
                foreach ($discussion->tags as $tag) {
                    if ($tag->slug === 'location-manager') {
                        $checker = new LicenseChecker();
                        if (!$checker->can_access($serializer->getActor())) {
                            $attributes['canReply'] = false;
                            // Add custom message for reply restriction
                            $attributes['replyPermissionMessage'] = 'You must have a valid Location Manager license to reply.';
                        }
                    }
                }
            }
            return $attributes;
        }),

    // Forum-wide permissions (for new discussions)
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(function(ForumSerializer $serializer, $forum, array $attributes) {
            $checker = new LicenseChecker();
            if (!$checker->can_access($serializer->getActor())) {
                $attributes['canStartDiscussion'] = false;
                $attributes['startDiscussionPermissionMessage'] = 'You must have a valid Location Manager license to start new discussions in this section.';
            }
            return $attributes;
        })
];