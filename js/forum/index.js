import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';

app.initializers.add('ayecode/flarum-restrictions', () => {
    extend(DiscussionControls, 'replyAction', function(items, discussion) {
        if (!discussion.canReply() && discussion.replyPermissionMessage()) {
            app.alerts.show(
                { type: 'warning' },
                discussion.replyPermissionMessage()
            );
        }
    });
});