module.exports=function(t){var e={};function o(n){if(e[n])return e[n].exports;var r=e[n]={i:n,l:!1,exports:{}};return t[n].call(r.exports,r,r.exports,o),r.l=!0,r.exports}return o.m=t,o.c=e,o.d=function(t,e,n){o.o(t,e)||Object.defineProperty(t,e,{enumerable:!0,get:n})},o.r=function(t){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(t,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(t,"__esModule",{value:!0})},o.t=function(t,e){if(1&e&&(t=o(t)),8&e)return t;if(4&e&&"object"==typeof t&&t&&t.__esModule)return t;var n=Object.create(null);if(o.r(n),Object.defineProperty(n,"default",{enumerable:!0,value:t}),2&e&&"string"!=typeof t)for(var r in t)o.d(n,r,function(e){return t[e]}.bind(null,r));return n},o.n=function(t){var e=t&&t.__esModule?function(){return t.default}:function(){return t};return o.d(e,"a",e),e},o.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},o.p="",o(o.s=0)}([function(t,e){const o=flarum.core.compat["forum/app"],{extend:n}=flarum.core.compat["common/extend"],r=flarum.core.compat["components/DiscussionPage"],i=flarum.core.compat["components/Page"],s=flarum.core.compat["components/IndexPage"],a=flarum.core.compat["components/SessionDropdown"];o.initializers.add("ayecode/flarum-restrictions",(function(){let t=null,e=null;n(i.prototype,"oninit",(function(){t&&(o.alerts.dismiss(t),t=null),e=null})),n(r.prototype,"onupdate",(function(){if((this.discussion?this.discussion.id():null)&&!t&&!this.discussion.canReply()){const e=this.discussion.tags();e&&e.some(t=>"general"!==t.slug())&&(t=o.alerts.show({type:"error"},"You must have a valid license to reply to this discussion."))}})),n(s.prototype,"onupdate",(function(){const n=this.currentTag&&"function"==typeof this.currentTag?this.currentTag():null;n&&n!==e&&(e=n,"general"===n.slug()||n.canStartDiscussion()||(t=o.alerts.show({type:"error"},"You must have a valid license to start a discussion in this section.")))})),n(a.prototype,"items",(function(t){const e=t._items.logOut;e&&e.content&&(e.content=m("button.Button.hasIcon",{onclick:function(t){t.preventDefault();const e=new FormData;e.append("nopriv_nonce","103d78334b6e9b8a4089ebce577411ed"),fetch("https://wpgeodirectory.com/support/wp-admin/admin-ajax.php?action=ayetheme_logout",{method:"POST",credentials:"include",body:e}).then(t=>t.json()).then(t=>{t.success?window.location.reload():o.alerts.show({type:"error"},"Logout failed. Please try again.")}).catch(t=>{o.alerts.show({type:"error"},"Logout failed. Please try again.")})}},[m("i.icon.fas.fa-sign-out-alt.Button-icon",{"aria-hidden":"true"}),m("span.Button-label","Log Out")]))}))}))}]);