import { oAuthToken } from "../oauth.js";

export async function getModulesList(){
    const response = await fetch(process.env.BASE_URL + '/api/telegram/modules-list', {
        method: 'GET',
        headers: {
                    'Authorization': `Bearer ${oAuthToken}`
               }
    })

    if(!response.ok) throw new Error(response.status);

    const modules = await response.json();

    modules.sort();
    
    return modules;
}

