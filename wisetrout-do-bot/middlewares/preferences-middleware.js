import { checkSubscription } from "../api-calls/subscription.js";


export default async function preferencesMiddleware(ctx, next){
    if(!ctx.session){
        const {subscribed, modules} = await checkSubscription(ctx.from.id);
        ctx.session = { subscribed, modules };
    }
    next();
}