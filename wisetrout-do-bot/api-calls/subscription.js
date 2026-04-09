import { oAuthToken } from "../oauth.js";

export async function subscribe(userInfo, modules){
    console.log('Creating subscription...');
    console.log(userInfo);
    console.log('Modules:');
    console.log(modules);

    const body = JSON.stringify(modules ? 
    {userInfo, modules} :
    {userInfo});

    console.log('Body:');
    console.log(body);
    
    
    try{
        
        const res = await fetch(process.env.BASE_URL + '/api/telegram/subscribe',  {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${oAuthToken}`,
            'Content-Type': 'application/json'
        },
        body
        })

        if(!res.ok) throw new Error(res.status);

        console.log('Success!');
        console.log(res);

    }catch(err){
        console.log(err);
        return null;
    }
}

export async function updateStatus(chatId, subscribed){
    console.log('Updating status...');

    const body = JSON.stringify({ chatId, subscribed });

    console.log('Body:');
    console.log(body);
    
    
    try{
        
        const res = await fetch(process.env.BASE_URL + '/api/telegram/update-status',  {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${oAuthToken}`,
            'Content-Type': 'application/json'
        },
        body
        })

        if(!res.ok) throw new Error(res.status);

        console.log('Success!');
        console.log(res);
        
    }catch(err){
        console.log(err);
    }
}

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
        

        const data = await res.json();

        console.log('User info:');
        console.log(data);
        

        return data;
        
        

    }catch(err){
        console.log(err);
        return null;
    }
}

export async function wipeoutData(chatId){
    console.log('Wipeout...');

    const body = JSON.stringify({ chatId });

    console.log('Body:');
    console.log(body);
    
    
    try{
        
        const res = await fetch(process.env.BASE_URL + '/api/telegram/wipeout',  {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${oAuthToken}`,
            'Content-Type': 'application/json'
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
    }
}