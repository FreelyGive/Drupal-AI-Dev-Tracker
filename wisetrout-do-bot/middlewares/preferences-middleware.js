import { checkSubscription } from "../api-calls/subscription.js";


export default async function preferencesMiddleware(ctx, next){
    if(!ctx.session){
        const userInfo = await checkSubscription(ctx.from.id);
        ctx.session = { userInfo };
    }
    next();
}