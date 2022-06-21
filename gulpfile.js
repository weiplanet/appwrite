// Include gulp
const { src, dest, series } = require('gulp');

// Plugins
const gulpConcat = require('gulp-concat');
const gulpJsmin = require('gulp-jsmin');
const gulpLess = require('gulp-less');
const gulpCleanCSS = require('gulp-clean-css');

// Config

const configApp = {
    mainFile: 'app.js',
    src: [
        'public/scripts/dependencies/litespeed.js',
        'public/scripts/dependencies/alpine.js',

        'public/scripts/init.js',

        'public/scripts/services/alerts.js',
        'public/scripts/services/api.js',
        'public/scripts/services/console.js',
        'public/scripts/services/date.js',
        'public/scripts/services/env.js',
        'public/scripts/services/form.js',
        'public/scripts/services/markdown.js',
        'public/scripts/services/rtl.js',
        'public/scripts/services/sdk.js',
        'public/scripts/services/search.js',
        'public/scripts/services/timezone.js',
        'public/scripts/services/realtime.js',

        'public/scripts/routes.js',
        'public/scripts/filters.js',
        'public/scripts/app.js',
        'public/scripts/upload-modal.js',
        'public/scripts/events.js',

        'public/scripts/views/service.js',

        'public/scripts/views/analytics/event.js',
        'public/scripts/views/analytics/activity.js',
        'public/scripts/views/analytics/pageview.js',

        'public/scripts/views/forms/clone.js',
        'public/scripts/views/forms/add.js',
        'public/scripts/views/forms/chart.js',
        'public/scripts/views/forms/chart-bar.js',
        'public/scripts/views/forms/code.js',
        'public/scripts/views/forms/color.js',
        'public/scripts/views/forms/copy.js',
        'public/scripts/views/forms/custom-id.js',
        'public/scripts/views/forms/document.js',
        'public/scripts/views/forms/duplications.js',
        'public/scripts/views/forms/document-preview.js',
        'public/scripts/views/forms/filter.js',
        'public/scripts/views/forms/headers.js',
        'public/scripts/views/forms/key-value.js',
        'public/scripts/views/forms/move-down.js',
        'public/scripts/views/forms/move-up.js',
        'public/scripts/views/forms/nav.js',
        'public/scripts/views/forms/oauth-custom.js',
        'public/scripts/views/forms/password-meter.js',
        'public/scripts/views/forms/pell.js',
        'public/scripts/views/forms/required.js',
        'public/scripts/views/forms/remove.js',
        'public/scripts/views/forms/run.js',
        'public/scripts/views/forms/select-all.js',
        'public/scripts/views/forms/selected.js',
        'public/scripts/views/forms/show-secret.js',
        'public/scripts/views/forms/switch.js',
        'public/scripts/views/forms/tags.js',
        'public/scripts/views/forms/text-count.js',
        'public/scripts/views/forms/text-direction.js',
        'public/scripts/views/forms/text-resize.js',
        'public/scripts/views/forms/upload.js',

        'public/scripts/views/general/cookies.js',
        'public/scripts/views/general/copy.js',
        'public/scripts/views/general/page-title.js',
        'public/scripts/views/general/scroll-to.js',
        'public/scripts/views/general/scroll-direction.js',
        'public/scripts/views/general/setup.js',
        'public/scripts/views/general/switch.js',
        'public/scripts/views/general/theme.js',
        'public/scripts/views/general/version.js',

        'public/scripts/views/paging/back.js',
        'public/scripts/views/paging/next.js',

        'public/scripts/views/ui/highlight.js',
        'public/scripts/views/ui/loader.js',
        'public/scripts/views/ui/modal.js',
        'public/scripts/views/ui/open.js',
        'public/scripts/views/ui/phases.js',
        'public/scripts/views/ui/trigger.js',
    ],

    dest: './public/dist/scripts'
};

const configDep = {
    mainFile: 'app-dep.js',
    src: [
        'public/scripts/dependencies/appwrite.js',
        'node_modules/chart.js/dist/chart.js',
        'node_modules/markdown-it/dist/markdown-it.js',
        'node_modules/pell/dist/pell.js',
        'node_modules/turndown/dist/turndown.js',
        // PrismJS Core
        'node_modules/prismjs/components/prism-core.min.js',
        // PrismJS Languages
        'node_modules/prismjs/components/prism-markup.min.js',
        'node_modules/prismjs/components/prism-css.min.js',
        'node_modules/prismjs/components/prism-clike.min.js',
        'node_modules/prismjs/components/prism-javascript.min.js',
        'node_modules/prismjs/components/prism-bash.min.js',
        'node_modules/prismjs/components/prism-csharp.min.js',
        'node_modules/prismjs/components/prism-dart.min.js',
        'node_modules/prismjs/components/prism-go.min.js',
        'node_modules/prismjs/components/prism-graphql.min.js',
        'node_modules/prismjs/components/prism-http.min.js',
        'node_modules/prismjs/components/prism-java.min.js',
        'node_modules/prismjs/components/prism-json.min.js',
        'node_modules/prismjs/components/prism-kotlin.min.js',
        'node_modules/prismjs/components/prism-markup-templating.min.js',
        'node_modules/prismjs/components/prism-php.min.js',
        'node_modules/prismjs/components/prism-powershell.min.js',
        'node_modules/prismjs/components/prism-python.min.js',
        'node_modules/prismjs/components/prism-ruby.min.js',
        'node_modules/prismjs/components/prism-swift.min.js',
        'node_modules/prismjs/components/prism-typescript.min.js',
        'node_modules/prismjs/components/prism-yaml.min.js',
        // PrismJS Plugins
        'node_modules/prismjs/plugins/line-numbers/prism-line-numbers.min.js',
    ],
    dest: './public/dist/scripts'
};

const config = {
    mainFile: 'app-all.js',
    src: [
        'public/dist/scripts/app-dep.js',
        'public/dist/scripts/app.js'
    ],
    dest: './public/dist/scripts'
};

function lessLTR() {
    return src('./public/styles/default-ltr.less')
        .pipe(gulpLess())
        .pipe(gulpCleanCSS({ compatibility: 'ie8' }))
        .pipe(dest('./public/dist/styles'));
}

function lessRTL() {
    return src('./public/styles/default-rtl.less')
        .pipe(gulpLess())
        .pipe(gulpCleanCSS({ compatibility: 'ie8' }))
        .pipe(dest('./public/dist/styles'));
}

function concatApp() {
    return src(configApp.src)
        .pipe(gulpConcat(configApp.mainFile))
        .pipe(gulpJsmin())
        .pipe(dest(configApp.dest));
}

function concatDep() {
    return src(configDep.src)
        .pipe(gulpConcat(configDep.mainFile))
        .pipe(gulpJsmin())
        .pipe(dest(configDep.dest));
}

function concat() {
    return src(config.src)
        .pipe(gulpConcat(config.mainFile))
        .pipe(dest(config.dest));
}

exports.import = series(concatDep);
exports.less = series(lessLTR, lessRTL);
exports.build = series(concatApp, concat);
