import { oAuthToken } from "../oauth.js";

export async function subscribe(userInfo, modules){
    console.log('Creating subscription...');
    console.log(userInfo);
    console.log('Modules:');
    console.log(modules);

    const body = modules ? 
    {userInfo, modules} :
    {userInfo}
    
    try{
        const url = process.env.BASE_URL + '/api/telegram/subscribe';
        console.log(url);
        
        const res = await fetch(process.env.BASE_URL + '/api/telegram/subscribe',  {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${oAuthToken}`
        },
        body
        })

        if(!res.ok) throw new Error(res.status);

        console.log('Success!');
        console.log(res);
        

        const json = await res.json();

        console.log('Data:');
        console.log(json);
        
        

    }catch(err){
        console.log(err);
        return null;
    }
}

export async function unsubscribe(chatId){}

export async function checkSubscription(chatId){
     try{
        
        const res = await fetch(`${process.env.BASE_URL}/api/telegram/user-info/${chatId}`,  {
        method: 'GET',
        headers: {
            'Authorization': `Bearer ${oAuthToken}`
        }
        })

        if(!res.ok) throw new Error(res.status);

        console.log('user Info success!');
        console.log(res);
        

        const json = await res.json();

        console.log('Data:');
        console.log(json);
        
        

    }catch(err){
        console.log(err);
        return null;
    }
}

export async function wipeoutData(chatId){}

// Imitation of server request taking time
async function wait(ms){
    return await new Promise((res, rej) => {
        setTimeout(() => res(), ms)
    })
}