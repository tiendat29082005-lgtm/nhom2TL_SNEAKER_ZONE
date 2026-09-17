// Shared transport for all three pages. Mutations require POST + a session CSRF token.
(() => {
  let tokenPromise;
  const writes=new Set(['register','user_login','user_logout','login','logout','create_order','save_product','delete_product','restore_product','update_order','toggle_customer','save_coupon','toggle_coupon']);
  window.szFetch=async (resource,options={})=>{
    const url=new URL(resource,location.href), action=url.searchParams.get('action');
    if(writes.has(action)){
      if(!tokenPromise)tokenPromise=fetch('api.php?action=csrf',{cache:'no-store'}).then(async r=>{const d=await r.json();if(!r.ok||!d.token)throw new Error('Không thể khởi tạo phiên.');return d.token;}).catch(e=>{tokenPromise=null;throw e;});
      const headers=new Headers(options.headers||{});headers.set('X-CSRF-Token',await tokenPromise);
      options={...options,method:'POST',headers};
    }
    return fetch(resource,{cache:'no-store',...options});
  };
})();
