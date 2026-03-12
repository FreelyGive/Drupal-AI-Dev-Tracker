export async function subscribe(chatId, modules){}

export async function unsubscribe(chatId){}

export async function checkSubscription(chatId){
    await wait(2000);
    return null;
}

export async function wipeoutData(chatId){}

// Imitation of server request taking time
async function wait(ms){
    return await new Promise((res, rej) => {
        setTimeout(() => res(), ms)
    })
}