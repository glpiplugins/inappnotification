// Configuration of vue-gettext-extract, run by GLPI's `tools:locales:extract` (make locales-extract)
// for the Vue files; same keywords as GLPI's core.
export default {
    input: {
        path: 'src/vue',
        include: ['**/*.vue'],
        parserOptions: {
            overrideDefaultKeywords: true,
            mapping: {
                simple: ['__'],
                plural: ['_n'],
                ctxPlural: ['_nx'],
                ctx: ['_x'],
            },
        },
    },
    output: {
        path: './',
        potPath: 'locales/vue.pot',
        locales: [],
        splitJson: false,
        linguas: false,
        flat: true,
    },
};
