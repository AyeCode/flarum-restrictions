const app = flarum.core.compat['forum/app'];
const { extend, override } = flarum.core.compat['common/extend'];
const DiscussionPage = flarum.core.compat['components/DiscussionPage'];
const Page = flarum.core.compat['components/Page'];
const IndexPage = flarum.core.compat['components/IndexPage'];
const HeaderSecondary = flarum.core.compat['components/HeaderSecondary'];
const LogInModal = flarum.core.compat['components/LogInModal'];
const SignUpModal = flarum.core.compat['components/SignUpModal'];
const Button = flarum.core.compat['components/Button'];


app.initializers.add('ayecode/flarum-restrictions', function () {
    // Define array of allowed forums
    const allowedForums = []; // removed access to all forums ['general', 'geodirectory-core']; // Forums that are allowed to be seen without a license

    let currentAlert = null;
    let lastCheckedTag = null;

    // Clear alert when leaving any page
    extend(Page.prototype, 'oninit', function() {
        if (currentAlert) {
            app.alerts.dismiss(currentAlert);
            currentAlert = null;
        }
        lastCheckedTag = null;
    });

    extend(DiscussionPage.prototype, 'onupdate', function () {
        const discussionId = this.discussion ? this.discussion.id() : null;

        // Only check if we have a discussion and haven't shown alert yet
        if (discussionId && !currentAlert && !this.discussion.canReply()) {
            // Check if discussion has tags and none of them are in allowedForums
            const tags = this.discussion.tags();
            const hasNonAllowedTag = tags && !tags.some(tag => allowedForums.includes(tag.slug()));

            if (hasNonAllowedTag) {
                currentAlert = app.alerts.show(
                    { type: 'error' },
                    'You must have a valid license to reply to this discussion.'
                );
            }
        }
    });

    extend(IndexPage.prototype, 'onupdate', function () {
        const currentTag = this.currentTag && typeof this.currentTag === 'function' ? this.currentTag() : null;

        // Only check if tag has changed and we haven't shown alert yet
        if (currentTag && currentTag !== lastCheckedTag) {
            lastCheckedTag = currentTag;

            if (!allowedForums.includes(currentTag.slug()) && !currentTag.canStartDiscussion()) {
                currentAlert = app.alerts.show(
                    { type: 'error' },
                    'You must have a valid license to start a discussion in this section.'
                );
            }
        }
    });

    // Disable "Start Discussion" button on homepage if user can't post to any forum
    extend(IndexPage.prototype, 'sidebarItems', function (items) {
        const currentTag = this.currentTag && typeof this.currentTag === 'function' ? this.currentTag() : null;

        // If we're on the homepage (no specific tag selected)
        if (!currentTag) {
            // Check if user can start discussion in any available tag
            const tags = app.store.all('tags');
            const canPostToAnyForum = tags.some(tag => tag.canStartDiscussion());

            // If user can't post to any forum, disable the start discussion button
            if (!canPostToAnyForum && items.has('newDiscussion')) {
                items.remove('newDiscussion');
            }
        }
    });


    // Log in with AyeCode: login and sign up both go to the store (OAuth, /auth/ayecode). Forum passwords are empty,
    // so there is nothing to type here. Log out is Flarum's own again (it used to call the old WordPress server).
    // app.forum is not loaded yet when initializers run, so the setting is read when each piece renders.
    const ssoOn = function () {
        return !!(app.forum && app.forum.attribute('ayecodeSso'));
    };
    const goToStore = function () {
        const base = app.forum.attribute('baseUrl');
        window.location.href = base + '/auth/ayecode?return=' + encodeURIComponent(window.location.href);
    };

    extend(HeaderSecondary.prototype, 'items', function (items) {
        if (!ssoOn()) {
            return;
        }
        if (items.has('signUp')) {
            items.remove('signUp');
        }
        if (items.has('logIn')) {
            items.setContent('logIn', m(Button, { className: 'Button Button--link', onclick: goToStore }, 'Log in with AyeCode'));
        }
    });

    // Anything else that asks for a login (reply as a guest, etc.) opens one of these modals: go to the store instead.
    [LogInModal, SignUpModal].forEach(function (Modal) {
        override(Modal.prototype, 'oninit', function (original, vnode) {
            if (ssoOn()) {
                goToStore();
            }
            return original(vnode);
        });
    });
});
