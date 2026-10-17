import assert from 'node:assert/strict';
import {test} from 'node:test';
import {gameTimes} from '../../public/game-clock.mjs';

test('часы EFT используют UTC ×7, сдвиг +3 и разницу 12 часов', () => {
  assert.deepEqual(gameTimes(0), ['03:00','15:00']);
  assert.deepEqual(gameTimes(60_000), ['03:07','15:07']);
  assert.deepEqual(gameTimes(3_600_000), ['10:00','22:00']);
  assert.deepEqual(gameTimes(3*3_600_000), ['00:00','12:00']);
  for (const now of [Date.now(), Date.UTC(2026,9,17,23,59,59), -1]) {
    const times = gameTimes(now);
    for (const value of times) assert.match(value, /^([01]\d|2[0-3]):[0-5]\d$/);
    const minutes = times.map(value => Number(value.slice(0,2))*60+Number(value.slice(3)));
    assert.equal((minutes[1]-minutes[0]+1440)%1440,720);
    assert.deepEqual(gameTimes(now+86_400_000), times);
  }
});
