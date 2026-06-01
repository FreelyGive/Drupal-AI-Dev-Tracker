const OAUTH_MAX_ATTEMPTS = 5;
const RETRY_INTERVAL_MS = 10000;

export async function getOAuthToken(){

    const data = {
        grant_type: 'client_credentials',
        client_id: process.env.DRUPAL_AUTH_TOKEN_CLIENT_ID || '',
        client_secret: process.env.DRUPAL_AUTH_TOKEN_CLIENT_SECRET || '',
        scope: process.env.DRUPAL_AUTH_TOKEN_SCOPE || '',
    };

    const body = new URLSearchParams(data);

    let attemptsDone = 0;

    do{
        attemptsDone++;
        try{
            const res = await fetch(process.env.BASE_URL + '/oauth/token',  {
            method: 'POST',
            body: body
            });
            if(!res.ok) throw new Error(res.status);
            const responseData = await res.json();
            return responseData;
        }catch(err){
            console.log('Failed attempt No ' + attemptsDone);
            console.log(err);
            await new Promise(res => {
            setTimeout(()=> {res()}, RETRY_INTERVAL_MS);
            });
            continue;
        }
        

    }while(attempts_done < OAUTH_MAX_ATTEMPTS);



}