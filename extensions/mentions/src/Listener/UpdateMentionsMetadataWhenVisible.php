<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\Mentions\Listener;

use Flarum\Approval\Event\PostWasApproved;
use Flarum\Extension\ExtensionManager;
use Flarum\Mentions\Notification\GroupMentionedBlueprint;
use Flarum\Mentions\Notification\PostMentionedBlueprint;
use Flarum\Mentions\Notification\UserMentionedBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Post\Event\Revised;
use Flarum\Post\Post;
use Flarum\User\User;
use s9e\TextFormatter\Utils;

class UpdateMentionsMetadataWhenVisible
{
    /**
     * @var NotificationSyncer
     */
    protected $notifications;

    /**
     * @var ExtensionManager
     */
    protected $extensions;

    public function __construct(NotificationSyncer $notifications, ExtensionManager $extensions)
    {
        $this->notifications = $notifications;
        $this->extensions = $extensions;
    }

    /**
     * @param Posted|Restored|Revised|PostWasApproved $event
     */
    public function handle($event)
    {
        if (! $event->post instanceof CommentPost) {
            return;
        }

        $content = $event->post->parsed_content;

        $this->syncUserMentions(
            $event->post,
            Utils::getAttributeValues($content, 'USERMENTION', 'id')
        );

        $this->syncPostMentions(
            $event->post,
            Utils::getAttributeValues($content, 'POSTMENTION', 'id')
        );

        $this->syncGroupMentions(
            $event->post,
            Utils::getAttributeValues($content, 'GROUPMENTION', 'id')
        );

        if ($this->extensions->isEnabled('flarum-tags')) {
            $this->syncTagMentions(
                $event->post,
                Utils::getAttributeValues($content, 'TAGMENTION', 'id')
            );
        }
    }

    protected function syncUserMentions(Post $post, array $mentioned)
    {
        $post->mentionsUsers()->sync($mentioned);
        $post->unsetRelation('mentionsUsers');

        $users = User::whereIn('id', $mentioned)
            ->get()
            ->filter(function ($user) use ($post) {
                return $post->isVisibleTo($user) && $user->id !== $post->user_id;
            })
            ->all();

        $this->notifications->sync(new UserMentionedBlueprint($post), $users);
    }

    protected function syncPostMentions(Post $reply, array $mentioned)
    {
        $reply->mentionsPosts()->sync($mentioned);
        $reply->unsetRelation('mentionsPosts');

        $posts = Post::with('user')
            ->whereIn('id', $mentioned)
            ->get()
            ->filter(function (Post $post) use ($reply) {
                return $post->user && $post->user_id !== $reply->user_id && $reply->isVisibleTo($post->user);
            })
            ->all();

        foreach ($posts as $post) {
            $this->notifications->sync(new PostMentionedBlueprint($post, $reply), [$post->user]);
        }
    }

    protected function syncGroupMentions(Post $post, array $mentioned)
    {
        $post->mentionsGroups()->sync($mentioned);
        $post->unsetRelation('mentionsGroups');

        $users = User::whereHas('groups', function ($query) use ($mentioned) {
            $query->whereIn('groups.id', $mentioned);
        })
            ->get()
            ->filter(function (User $user) use ($post) {
                return $post->isVisibleTo($user) && $user->id !== $post->user_id;
            })
            ->all();

        $this->notifications->sync(new GroupMentionedBlueprint($post), $users);
    }

    protected function syncTagMentions(Post $post, array $mentioned)
    {
        $post->mentionsTags()->sync($mentioned);
        $post->unsetRelation('mentionsTags');
    }
}
