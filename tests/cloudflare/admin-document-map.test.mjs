import test from 'node:test';
import assert from 'node:assert/strict';
import {mapPercent,validCoordinates,pointKey} from '../../public/admin-document-map.mjs';
test('Проценты относятся к фактической плоскости после масштаба и прокрутки',()=>{
 assert.deepEqual(mapPercent(350,240,{left:100,top:40,width:500,height:400}),{x:50,y:50});
 assert.deepEqual(mapPercent(600,440,{left:100,top:40,width:500,height:400}),{x:100,y:100});
 assert.equal(mapPercent(99,240,{left:100,top:40,width:500,height:400}),null);
 assert.equal(mapPercent(100,40,{left:100,top:40,width:0,height:400}),null);
 for(const x of [NaN,Infinity,-1,101,'50',null])assert.equal(validCoordinates({x,y:10}),false);
 assert.equal(validCoordinates({x:0,y:100}),true);
});
test('Альтернативные позиции и разные виды точек не подменяют друг друга',()=>{
 assert.notEqual(pointKey({kind:'exit',pointId:5}),pointKey({kind:'document',pointId:5}));
 assert.notEqual(pointKey({kind:'document',pointId:5,positionId:1}),pointKey({kind:'document',pointId:5,positionId:2}));
});
