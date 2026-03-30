import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule } from '@angular/forms';
import { RouterModule, Routes } from '@angular/router';
import { LoginComponent } from './login/login.component';
import { ResetPasswordComponent } from './reset-password/reset-password.component';

const routes: Routes = [
  { path: '', component: LoginComponent },
  { path: 'reset-password', component: ResetPasswordComponent }
];

@NgModule({
  declarations: [LoginComponent, ResetPasswordComponent],
  imports: [
    CommonModule,
    ReactiveFormsModule,
    RouterModule.forChild(routes)
  ]
})
export class AuthModule {}
