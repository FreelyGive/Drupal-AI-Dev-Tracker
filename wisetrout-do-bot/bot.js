import { Markup, Telegraf, session } from 'telegraf';
import preferencesMiddleware from './middlewares/preferences-middleware.js';
import { subscribe, unsubscribe } from './api-calls/subscription.js';
import startHandler from './commands/start.js';
import { subscriptionMiddleware } from './middlewares/subscription-middleware.js';

const bot = new Telegraf(process.env.BOT_TOKEN);

bot.use(session());

bot.use(preferencesMiddleware);
bot.use(subscriptionMiddleware)

bot.start(startHandler);

bot.action('sub', ctx => {
    ctx.scene.enter('subscription-menu');
})

bot.action('unsub', async ctx => {
    await Promise.all([
        ctx.reply('Cancelling subscription...'),
        unsubscribe(ctx.from.id)
    ]);
    
    ctx.session.subscribed = false;
    ctx.reply('Subscription cancelled');
});

bot.launch();

// Enable graceful stop
process.once('SIGINT', () => bot.stop('SIGINT'))
process.once('SIGTERM', () => bot.stop('SIGTERM'))