import { oAuthToken } from "../oauth";

export async function getModulesList(){
    const response = await fetch(process.env.BASE_URL + '/api/telegram/modules-list', {
        method: 'GET',
        headers: {
                    'Authorization': `Bearer ${oAuthToken}`
               }
    })

    if(!response.ok) throw new Error(response.status)

    return await response.json();
}