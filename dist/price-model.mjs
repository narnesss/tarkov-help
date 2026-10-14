export const marketDay = now => new Date(Number(now)+3*60*60*1000).toISOString().slice(0,10);
const finite = value => Number.isFinite(Number(value)) && Number(value)>0 ? Number(value) : null;
const gameId = value => typeof value==='string' && /^[a-f\d]{24}$/.test(value);
export function normalizePrices(raw,provider){
  const rows=provider==='tarkov-market'?raw:Object.values(raw?.data?.items||{});
  if(!Array.isArray(rows))throw new Error('invalid_payload');
  const items={};
  for(const item of rows){
    const id=provider==='tarkov-market'?item.bsgId:item.id;if(!gameId(id))continue;
    if(provider==='tarkov-market'){
      const usable=item.haveMarketData!==false&&!item.bannedOnFlea;
      items[id]={lastLowPrice:usable?finite(item.price):null,avg24hPrice:usable?finite(item.avg24hPrice):null,
        updated:item.updated||null,bannedOnFlea:!!item.bannedOnFlea,
        marketTraderName:item.traderName||null,marketTraderPrice:finite(item.traderPriceRub),marketTraderCurrency:'RUB'};
    }else{
      items[id]={lastLowPrice:finite(item.lastLowPrice),avg24hPrice:finite(item.avg24hPrice),updated:item.updated||null,
        buyFromTrader:item.buyFromTrader||[],sellToTrader:item.sellToTrader||[]};
    }
  }
  if(Object.keys(items).length<100)throw new Error('incomplete_payload');
  return items;
}
