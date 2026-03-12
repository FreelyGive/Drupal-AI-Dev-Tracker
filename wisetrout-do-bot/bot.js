import { Telegraf, session } from 'telegraf';
import preferencesMiddleware from './middlewares/preferences-middleware.js';
import { scene as subscrScene } from './scenes/subscription-menu.js';
import { createActionsKeyboard } from './markup/keyboards.js';
import { scene as wipeoutScene } from './scenes/wipeout.js';
import { subscribe, unsubscribe } from './api-calls/subscription.js';
import { Stage } from 'telegraf/scenes';

const bot = new Telegraf(process.env.BOT_TOKEN);

bot.use(session());

const stage = new Stage([subscrScene, wipeoutScene]);

bot.use(preferencesMiddleware);
bot.use(stage.middleware());

bot.start(ctx => {
    ctx.reply(
        'Welcome to the Wisetrout Drupal org bot!', 
        createActionsKeyboard(ctx)
    )
});

bot.action('sub', ctx => {
    ctx.scene.enter('subscription-menu');
})

bot.action('unsub', async ctx => {
    await Promise.all([
        ctx.reply('Cancelling your subscription...'),
        unsubscribe(ctx.from.id)
    ]);

    ctx.session.userInfo.subscribed = false;
    ctx.reply('Unsubscribed from all updates. We will keep your preferences saved in case you want to renew your subscription.', 
        createActionsKeyboard(ctx)
    );
});

bot.action('resub', async ctx => {

    await Promise.all([
        ctx.reply('Reactivating your subscription...'),
        subscribe(ctx.from.id, ctx.session.userInfo.modules)
    ]);

    ctx.session.userInfo.subscribed = true;
    ctx.reply('Subscription reactivated', 
        createActionsKeyboard(ctx)
    );

})

bot.action('wipeout', async ctx => {
    ctx.scene.enter('wipeout');
});

// bot.action('help', async ctx => {
//     ctx.reply('You may use the following commands to manage your subscription:',
//         createActionsKeyboard(ctx)
//     );
// });

bot.launch();

// Enable graceful stop
process.once('SIGINT', () => bot.stop('SIGINT'))
process.once('SIGTERM', () => bot.stop('SIGTERM'))