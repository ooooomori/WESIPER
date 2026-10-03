import unittest
from datetime import date
from movement_player_state import plan_number_updates,apply_state_updates

TODAY=date(2026,10,3)
START=date(2026,9,19)


def row(old='22',new='33',day='2026-10-02',team='삼성',kind='등번호 변경',**extra):
    return dict(player_id=12345,source_key=f'{day}|{team}|{old}|{new}',event_date=day,event_type=kind,team=team,old_back_no=old,new_back_no=new,note=old+'→'+new if old and new else '',**extra)


class NumberUpdateTest(unittest.TestCase):
    def plan(self,rows,number='22',team='삼성',status=1):
        return plan_number_updates(rows,{12345:{'team':team,'is_kbodle':status,'backNo':number}},TODAY,START)

    def test_number_updates(self):
        updates,pending=self.plan([row()])
        self.assertEqual(updates[0]['changes'],{'backNo':'33'})
        self.assertFalse(pending)

    def test_latest_number_wins(self):
        updates,_=self.plan([row('33','44'),row('22','33','2026-10-01')])
        self.assertEqual(updates[0]['changes'],{'backNo':'44'})

    def test_same_day_chain(self):
        updates,_=self.plan([row('33','44'),row('22','33')])
        self.assertEqual(updates[0]['changes'],{'backNo':'44'})

    def test_same_day_conflict_needs_review(self):
        updates,pending=self.plan([row('22','33'),row('22','44')])
        self.assertFalse(updates);self.assertEqual(len(pending),1)

    def test_invalid_and_valid_mixed_do_not_write(self):
        invalid=row();invalid.update(old_back_no=None,new_back_no=None,note='不明')
        updates,pending=self.plan([row(),invalid])
        self.assertFalse(updates);self.assertEqual(len(pending),1)

    def test_legacy_notes_are_parsed(self):
        r=row();r.update(old_back_no=None,new_back_no=None,note='22 → 33')
        updates,_=self.plan([r])
        self.assertEqual(updates[0]['changes'],{'backNo':'33'})

    def test_zero_and_double_zero_preserved(self):
        for number in ['0','00']:
            updates,_=self.plan([row('22',number)])
            self.assertEqual(updates[0]['changes'],{'backNo':number})

    def test_old_future_and_undated_ignored(self):
        self.assertEqual(self.plan([row(day='2026-09-01'),row(day='2026-10-04'),row(day=None)]),([],[]))

    def test_previous_club_number_does_not_overwrite(self):
        updates,pending=self.plan([row()],team='LG')
        self.assertFalse(updates);self.assertEqual(len(pending),1)

    def test_later_transfer_blocks_earlier_number(self):
        self.assertEqual(self.plan([row(day='2026-10-01'),row(team='LG',kind='트레이드')]),([],[]))

    def test_inactive_and_overseas_not_updated(self):
        for status in [0,3]:self.assertEqual(self.plan([row()],status=status),([],[]))

    def test_ulsan_number_updates(self):
        updates,_=self.plan([row(team='울산웨일즈')],team='울산',status=4)
        self.assertEqual(updates[0]['changes'],{'backNo':'33'})

    def test_idempotent(self):
        self.assertEqual(self.plan([row()],number='33'),([],[]))

    def test_write_has_concurrent_number_guard(self):
        class Cursor:
            rowcount=1
            def __enter__(self):return self
            def __exit__(self,*args):pass
            def execute(self,sql,args):self.sql,self.args=sql,args
        class Connection:
            c=Cursor()
            def cursor(self):return self.c
        updates,_=self.plan([row()]);con=Connection()
        parents={12345:{'team':'삼성','is_kbodle':1,'backNo':'22'}}
        apply_state_updates(con,parents,updates,True)
        self.assertIn('AND backNo <=> %s',con.c.sql)
        self.assertEqual(con.c.args[-1],'22')
        self.assertEqual(parents[12345]['backNo'],'33')


if __name__=='__main__':unittest.main()
